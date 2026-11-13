<?php

namespace App\Services\Halls;

use App\Contracts\PaymentGateway;
use App\Exceptions\ModuleNotActiveException;
use App\Models\Hall;
use App\Models\HallBlockedDate;
use App\Models\HallBooking;
use App\Models\HallSlot;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ModuleActivationService;
use App\Services\TimezoneResolver;
use App\Services\WalletService;
use App\Support\HallSettings;
use App\Support\Modules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Function halls R2 (owner 2026-11-13) — booking a hall. Online (Razorpay) or wallet only; no cash and no benefit of any
 * kind (no coupon on halls). No payment, no booking: an online booking holds its slot for Hall Settings' unpaid hold;
 * the Razorpay capture WEBHOOK (never the browser) marks it paid. A capture that arrives after the hold ran out, or a
 * second capture, is refunded in full. Wallet pays at once (the customer's own money).
 *
 * Paid → the owner accepts within Hall Settings' hours. Declined, or not answered in time → refunded in full.
 *
 * Money: hall price (the slot's price) + GST (Hall Settings bands on that price; blank = none; only when 1CallFix
 * collects it) + booking fee = total. Pay now = the advance % of (price + GST) + the fee; when the date is closer than
 * the balance due days, everything now, deposit included. Otherwise the balance and the owner's refundable deposit are
 * due Hall Settings' days before the date (collected in R3). The bill the customer sees (quote) is what is charged.
 *
 * Two bookings of one hall never overlap in time: a slot's time on its date (past midnight = the next day) is checked
 * against every booking still holding its slot and every blocked day / slot, under a lock on the hall row.
 */
class HallBookingService
{
    public function __construct(private PaymentGateway $gateway)
    {
    }

    public static function scope(Hall $hall): array
    {
        $hall->loadMissing('franchise');

        return $hall->scope();
    }

    public static function tz(Hall $hall): string
    {
        return app(TimezoneResolver::class)->timezoneFor($hall->franchise);
    }

    /** The slot's start and end on that date, in UTC. A slot ending at or before its start time ends the next day. */
    public static function range(HallSlot $slot, string $date, string $tz): array
    {
        $start = Carbon::createFromFormat('Y-m-d H:i', $date.' '.substr((string) $slot->start_time, 0, 5), $tz);
        $end = Carbon::createFromFormat('Y-m-d H:i', $date.' '.substr((string) $slot->end_time, 0, 5), $tz);
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return [$start->utc(), $end->utc()];
    }

    /** Dates a customer may choose: from Hall Settings' minimum to maximum days ahead (local dates). */
    public static function dateWindow(Hall $hall): array
    {
        $scope = self::scope($hall);
        $today = now(self::tz($hall))->startOfDay();

        return [
            $today->copy()->addDays((int) HallSettings::get('hall.min_days_ahead', $scope))->toDateString(),
            $today->copy()->addDays((int) HallSettings::get('hall.max_days_ahead', $scope))->toDateString(),
        ];
    }

    /** Customers can book it: approved, switched on, and Function Halls on in its area. */
    public static function bookable(Hall $hall): bool
    {
        return $hall->isLive() && app(ModuleActivationService::class)->isActive(Modules::HALLS, self::scope($hall));
    }

    /** Free on that date: nothing holding an overlapping time, nothing blocked. Read-only; book() re-checks under a lock. */
    public static function isFree(Hall $hall, HallSlot $slot, string $date, ?int $ignoreBookingId = null): bool
    {
        $tz = self::tz($hall);
        [$start, $end] = self::range($slot, $date, $tz);

        $taken = HallBooking::where('hall_id', $hall->id)->whereIn('status', HallBooking::HOLDING)
            ->when($ignoreBookingId, fn ($q) => $q->whereKeyNot($ignoreBookingId))
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            // An unpaid booking whose hold ran out no longer holds the slot (the expiry job tidies it up).
            ->where(fn ($q) => $q->where('status', '!=', 'pending_payment')->orWhere('payment_expires_at', '>', now()))
            ->exists();
        if ($taken) {
            return false;
        }

        $day = Carbon::parse($date);
        $blocks = HallBlockedDate::with('slot')->where('hall_id', $hall->id)
            ->whereBetween('date', [$day->copy()->subDay()->toDateString(), $day->copy()->addDay()->toDateString()])->get();
        foreach ($blocks as $b) {
            $d = $b->date->toDateString();
            [$bs, $be] = $b->slot ? self::range($b->slot, $d, $tz)
                : [Carbon::parse($d, $tz)->startOfDay()->utc(), Carbon::parse($d, $tz)->addDay()->startOfDay()->utc()];
            if ($bs->lt($end) && $be->gt($start)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The bill for this slot on this date.
     *
     * @return array{price: float, gst_percent: float, gst: float, fee: float, total: float, deposit: float, pay_now: float, later: float, full_now: bool, balance_due_on: ?string, taxes_known: bool, collects_gst: bool}
     */
    public static function quote(Hall $hall, HallSlot $slot, string $date): array
    {
        $scope = self::scope($hall);
        $price = round((float) $slot->price, 2);
        $collect = HallSettings::on('hall.platform_collects_gst', $scope);
        $percent = HallSettings::gstPercentFor($price);
        // GST rounded to the rupee (CGST Act s.170), so the hall page and the payment show one number.
        $gst = $collect && $percent !== null ? round($price * $percent / 100) : 0.0;
        $fee = round((float) (HallSettings::get('hall.booking_fee', $scope) ?? 0), 2);
        $total = round($price + $gst + $fee, 2);
        $deposit = round((float) ($hall->deposit_amount ?? 0), 2);

        $dueDays = (int) HallSettings::get('hall.balance_due_days', $scope);
        $advancePercent = (int) HallSettings::get('hall.advance_percent', $scope);
        $today = now(self::tz($hall))->startOfDay();
        $dueOn = Carbon::parse($date, self::tz($hall))->startOfDay()->subDays($dueDays);
        $fullNow = $advancePercent >= 100 || $dueOn->lessThanOrEqualTo($today);

        // The advance (the part a late cancellation keeps) is the same whether paid alone or with everything.
        $advance = round(round(($price + $gst) * min(100, $advancePercent) / 100) + $fee, 2);
        $payNow = $fullNow ? round($total + $deposit, 2) : $advance;

        return [
            'advance' => $advance,
            'price' => $price,
            'gst_percent' => (float) ($percent ?? 0),
            'gst' => $gst,
            'fee' => $fee,
            'total' => $total,
            'deposit' => $deposit,
            'pay_now' => $payNow,
            'later' => round(max(0, $total + $deposit - $payNow), 2),
            'full_now' => $fullNow,
            'balance_due_on' => $fullNow ? null : $dueOn->toDateString(),
            'taxes_known' => $percent !== null || ! $collect,
            'collects_gst' => $collect,
        ];
    }

    /**
     * @param  array{occasion?: ?string, guests?: ?int, notes?: ?string}  $extra
     *
     * @throws \RuntimeException with a customer-facing reason
     */
    public function book(User $customer, Hall $hall, HallSlot $slot, string $date, string $method, string $name, ?string $phone, array $extra = []): HallBooking
    {
        $hall->loadMissing('franchise');
        $scope = self::scope($hall);
        if (! app(ModuleActivationService::class)->isActive(Modules::HALLS, $scope)) {
            throw new ModuleNotActiveException(Modules::HALLS);
        }
        if (! $hall->isLive()) {
            throw new \RuntimeException('This hall is not taking bookings right now.');
        }
        $slot = HallSlot::find($slot->id);
        if ($slot === null || $slot->hall_id !== $hall->id || ! $slot->is_active) {
            throw new \RuntimeException('Choose a slot again.');
        }
        if (! in_array($method, ['online', 'wallet'], true)) {
            throw new \RuntimeException('Choose how to pay.');
        }
        [$first, $last] = self::dateWindow($hall);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $first || $date > $last) {
            throw new \RuntimeException('Choose a date between '.Carbon::parse($first)->format('j M Y').' and '.Carbon::parse($last)->format('j M Y').'.');
        }
        $quote = self::quote($hall, $slot, $date);
        if (! $quote['taxes_known']) {
            throw new \RuntimeException('Booking opens soon for this hall.');
        }
        [$start, $end] = self::range($slot, $date, self::tz($hall));
        if ($start->isPast()) {
            throw new \RuntimeException('This slot has already started. Choose another date.');
        }

        $booking = DB::transaction(function () use ($customer, $hall, $slot, $date, $method, $name, $phone, $extra, $quote, $scope, $start, $end) {
            // One booking at a time per hall: two customers never take the same time.
            Hall::whereKey($hall->id)->lockForUpdate()->first();
            if (! self::isFree($hall, $slot, $date)) {
                throw new \RuntimeException('This slot was just taken. Please choose another date or slot.');
            }

            $booking = HallBooking::create([
                'code' => HallBooking::newCode(),
                'hall_id' => $hall->id,
                'hall_slot_id' => $slot->id,
                'customer_id' => $customer->id,
                'franchise_id' => $hall->franchise_id,
                'zone_id' => $hall->zone_id,
                'event_date' => $date,
                'slot_name' => $slot->name,
                'starts_at' => $start,
                'ends_at' => $end,
                'occasion' => filled($extra['occasion'] ?? null) ? mb_substr(trim($extra['occasion']), 0, 80) : null,
                'guests' => ! empty($extra['guests']) ? (int) $extra['guests'] : null,
                'customer_name' => trim($name),
                'customer_phone' => filled($phone) ? trim($phone) : null,
                'notes' => filled($extra['notes'] ?? null) ? mb_substr(trim($extra['notes']), 0, 1000) : null,
                'status' => 'pending_payment',
                'payment_method' => $method,
                'payment_status' => 'pending',
                'hall_price' => $quote['price'],
                'gst_percent' => $quote['gst_percent'],
                'gst_amount' => $quote['gst'],
                'platform_collects_gst' => $quote['collects_gst'],
                'booking_fee' => $quote['fee'],
                'total' => $quote['total'],
                'deposit_amount' => $quote['deposit'],
                'pay_now' => $quote['pay_now'],
                'advance_amount' => $quote['advance'],
                'paid_in_full' => $quote['full_now'],
                'balance_due_on' => $quote['balance_due_on'],
                'payment_expires_at' => $method === 'online' ? now()->addMinutes((int) HallSettings::get('hall.unpaid_hold_minutes', $scope)) : null,
            ]);

            if ($method === 'wallet') {
                if (Setting::get('payment.wallet_enabled', null, array_filter(['zone_id' => $hall->zone_id, 'franchise_id' => $hall->franchise_id])) !== '1') {
                    throw new \RuntimeException('Wallet payments are not enabled.');
                }
                app(WalletService::class)->debit($customer, (float) $booking->pay_now, reason: "Hall booking {$booking->code} ({$hall->name})", ref: "hall_booking:{$booking->id}:wallet-payment");
                Payment::create([
                    'hall_booking_id' => $booking->id, 'purpose' => 'hall_booking', 'amount' => $booking->pay_now,
                    'gateway' => 'wallet', 'status' => 'captured', 'captured_at' => now(),
                ]);
                $this->received($booking);
            }

            return $booking;
        });

        return $booking->fresh();
    }

    /** @return array{payment_id: int, razorpay_order_id: string, razorpay_key_id: ?string, amount: int, currency: string} */
    public function startOnlinePayment(HallBooking $booking): array
    {
        if (! $booking->isAwaitingOnlinePayment() || ($booking->payment_expires_at && $booking->payment_expires_at->isPast())) {
            throw new \RuntimeException('This booking can no longer be paid. Please book again.');
        }
        if (! $this->gateway->isConfigured()) {
            throw new \RuntimeException('Online payment is not available right now.');
        }
        if (Setting::get('payment.online_enabled', '1', array_filter(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id])) !== '1') {
            throw new \RuntimeException('Online payments are currently disabled.');
        }

        $gatewayOrder = $this->gateway->createRawOrder((float) $booking->pay_now, 'hall-'.$booking->id.'-'.now()->format('His'),
            ['hall_booking_id' => $booking->id, 'purpose' => 'hall_booking']);
        $payment = Payment::create([
            'hall_booking_id' => $booking->id, 'purpose' => 'hall_booking', 'amount' => $booking->pay_now,
            'gateway' => $this->gateway->identifier(), 'gateway_order_id' => $gatewayOrder['razorpay_order_id'], 'status' => 'pending',
        ]);

        return [
            'payment_id' => $payment->id,
            'razorpay_order_id' => $gatewayOrder['razorpay_order_id'],
            'razorpay_key_id' => $gatewayOrder['key_id'],
            'amount' => $gatewayOrder['amount'],
            'currency' => $gatewayOrder['currency'],
        ];
    }

    /**
     * Webhook capture (RazorpayWebhookHandler, after its amount check and pending→captured lock: runs once per payment).
     * Paid → waiting for the owner; the booking ended meanwhile (not paid in time) or already paid → money back in full.
     */
    public function markPaid(Payment $payment): void
    {
        $booking = $payment->hallBooking;
        if (! $booking) {
            return;
        }
        if ($booking->balance_payment_id === $payment->id) {
            $this->markBalancePaid($booking, $payment);

            return;
        }

        $outcome = DB::transaction(function () use ($booking) {
            $locked = HallBooking::whereKey($booking->id)->lockForUpdate()->first();
            if (! $locked || $locked->payment_status === 'paid') {
                return 'already_paid';
            }
            if ($locked->status !== 'pending_payment') {
                return 'ended';
            }
            // Paid late, after the hold ran out: still ours only if nobody took the slot meanwhile.
            if ($locked->payment_expires_at?->isPast()) {
                Hall::whereKey($locked->hall_id)->lockForUpdate()->first();
                $slot = HallSlot::find($locked->hall_slot_id);
                if (! $slot || ! self::isFree($locked->hall()->first(), $slot, $locked->event_date->toDateString(), $locked->id)) {
                    $locked->forceFill(['status' => 'expired'])->save();

                    return 'ended';
                }
            }
            $this->received($locked);

            return 'paid';
        });

        if ($outcome !== 'paid') {
            $this->refundPaymentInFull($booking->fresh(), $payment, $outcome === 'ended' ? 'booking ended before the payment arrived' : 'paid twice');
        }
    }

    /** Paid: the owner is asked to accept. Call inside a transaction. */
    private function received(HallBooking $booking): void
    {
        $hours = (int) HallSettings::get('hall.owner_accept_hours', array_filter(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]));
        $booking->forceFill([
            'status' => 'awaiting_owner', 'payment_status' => 'paid', 'paid_at' => now(), 'payment_expires_at' => null,
            'paid_amount' => $booking->pay_now, 'accept_by' => now()->addHours(max(1, $hours)),
            // Paid in full (the date was close): the deposit is already with us.
            'deposit_status' => $booking->paid_in_full && (float) $booking->deposit_amount > 0 ? 'held' : 'none',
        ])->save();
        DB::afterCommit(function () use ($booking) {
            $b = $booking->fresh(['hall.provider.user', 'customer']);
            $this->notifyCustomer($b, 'received');
            $this->notifyOwner($b, 'new');
        });
    }

    /** The owner says yes (before the answer time runs out). */
    public function accept(HallBooking $booking, User $owner): HallBooking
    {
        $booking = DB::transaction(function () use ($booking, $owner) {
            $locked = HallBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'awaiting_owner') {
                throw new \RuntimeException('This booking is no longer waiting for you.');
            }
            if ($locked->accept_by?->isPast()) {
                throw new \RuntimeException('The time to answer has passed; the customer is being refunded.');
            }
            $locked->forceFill(['status' => 'confirmed', 'accepted_at' => now()])->save();
            ActivityLogger::logModel($owner, $locked, 'Hall booking accepted (hall portal)');
            DB::afterCommit(fn () => $this->notifyCustomer($locked->fresh(['hall', 'customer']), 'confirmed'));

            return $locked;
        });

        return $booking->fresh();
    }

    /** The owner says no: the customer gets everything back. */
    public function decline(HallBooking $booking, User $owner, string $reason): HallBooking
    {
        return $this->endWithFullRefund($booking, trim($reason), $owner, 'Hall booking declined (hall portal)');
    }

    /** Not answered in time: declined and refunded in full (scheduled, every minute). */
    public function declineUnanswered(): int
    {
        $count = 0;
        HallBooking::where('status', 'awaiting_owner')->whereNotNull('accept_by')->where('accept_by', '<', now())
            ->orderBy('id')->limit(100)->pluck('id')
            ->each(function (int $id) use (&$count) {
                try {
                    $this->endWithFullRefund(HallBooking::findOrFail($id), 'The hall did not answer in time', null, 'Hall booking declined: owner did not answer in time');
                    $count++;
                } catch (\Throwable $e) {
                    report($e); // the next run tries again
                }
            });

        return $count;
    }

    private function endWithFullRefund(HallBooking $booking, string $reason, ?User $by, string $log): HallBooking
    {
        $refunded = 0.0;
        $booking = DB::transaction(function () use ($booking, $reason, $by, $log, &$refunded) {
            $locked = HallBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'awaiting_owner') {
                throw new \RuntimeException('This booking is no longer waiting for an answer.');
            }
            $refunded = $this->refund($locked, (float) $locked->paid_amount, $reason);
            $locked->forceFill([
                'status' => 'declined', 'declined_at' => now(), 'decline_reason' => mb_substr($reason, 0, 500),
                'refunded_amount' => round((float) $locked->refunded_amount + $refunded, 2),
                'payment_status' => $refunded >= (float) $locked->paid_amount ? 'refunded' : 'partially_refunded',
            ])->save();
            ActivityLogger::logModel($by, $locked, $log, ['reason' => $reason, 'refunded' => $refunded]);

            return $locked;
        });
        rescue(fn () => $this->notifyCustomer($booking->fresh(['hall', 'customer']), 'declined', $refunded));

        return $booking->fresh();
    }

    /** No payment, no booking: unpaid online bookings past the hold free their slot (scheduled, every minute). */
    public function expireUnpaid(): int
    {
        return HallBooking::where('status', 'pending_payment')->where('payment_status', 'pending')
            ->whereNotNull('payment_expires_at')->where('payment_expires_at', '<', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);
    }

    private function refundPaymentInFull(HallBooking $booking, Payment $payment, string $why): void
    {
        if ($payment->status !== 'captured' || (float) $payment->refunded_amount > 0) {
            return;
        }
        $this->gateway->refund($payment->gateway_payment_id, (float) $payment->amount, "Hall booking {$booking->code}: {$why}");
        $payment->forceFill(['refunded_amount' => $payment->amount, 'status' => 'refunded'])->save();
    }

    /**
     * Money back to where it came from (the wallet, or the card / UPI through Razorpay), newest payment first, so a
     * deposit paid with the balance comes back from the balance payment. Returns the amount sent back.
     */
    private function refund(HallBooking $booking, float $amount, string $why, string $kind = 'decline'): float
    {
        $left = round($amount, 2);
        $sent = 0.0;
        $payments = Payment::where('hall_booking_id', $booking->id)->whereIn('status', ['captured', 'refunded'])->orderByDesc('id')->get();
        foreach ($payments as $payment) {
            if ($left <= 0) {
                break;
            }
            $room = round((float) $payment->amount - (float) $payment->refunded_amount, 2);
            $part = min($left, $room);
            if ($part <= 0) {
                continue;
            }
            if ($payment->gateway === 'wallet') {
                app(WalletService::class)->credit($booking->customer, $part, reason: "Refund: hall booking {$booking->code} ({$why})", ref: "hall_booking:{$booking->id}:wallet-refund:{$kind}-{$payment->id}");
            } else {
                $this->gateway->refund((string) $payment->gateway_payment_id, $part, "Hall booking {$booking->code}: {$why}");
            }
            $refunded = round((float) $payment->refunded_amount + $part, 2);
            $payment->forceFill(['refunded_amount' => $refunded, 'status' => $refunded >= (float) $payment->amount ? 'refunded' : 'captured'])->save();
            $left = round($left - $part, 2);
            $sent = round($sent + $part, 2);
        }

        return $sent;
    }

    // ─────────────────────────── R3: balance ───────────────────────────

    /** Days of grace after the balance due date before an unpaid booking is cancelled (fixed: one less setting). */
    public const BALANCE_GRACE_DAYS = 2;

    /** @return array{payment_id: int, razorpay_order_id: string, razorpay_key_id: ?string, amount: int, currency: string} */
    public function startBalancePayment(HallBooking $booking): array
    {
        if (! $booking->balanceOpen()) {
            throw new \RuntimeException('There is nothing to pay on this booking now.');
        }
        if (! $this->gateway->isConfigured()) {
            throw new \RuntimeException('Online payment is not available right now.');
        }
        $amount = $booking->balanceLater();
        $gatewayOrder = $this->gateway->createRawOrder($amount, 'hallbal-'.$booking->id.'-'.now()->format('His'),
            ['hall_booking_id' => $booking->id, 'purpose' => 'hall_booking', 'part' => 'balance']);
        $payment = Payment::create([
            'hall_booking_id' => $booking->id, 'purpose' => 'hall_booking', 'amount' => $amount,
            'gateway' => $this->gateway->identifier(), 'gateway_order_id' => $gatewayOrder['razorpay_order_id'], 'status' => 'pending',
        ]);
        $booking->forceFill(['balance_payment_id' => $payment->id])->save();

        return [
            'payment_id' => $payment->id,
            'razorpay_order_id' => $gatewayOrder['razorpay_order_id'],
            'razorpay_key_id' => $gatewayOrder['key_id'],
            'amount' => $gatewayOrder['amount'],
            'currency' => $gatewayOrder['currency'],
        ];
    }

    /** The balance (and deposit) from the customer's own wallet, at once. */
    public function payBalanceFromWallet(HallBooking $booking, User $customer): HallBooking
    {
        if ($booking->customer_id !== $customer->id || ! $booking->balanceOpen()) {
            throw new \RuntimeException('There is nothing to pay on this booking now.');
        }
        if (Setting::get('payment.wallet_enabled', null, array_filter(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id])) !== '1') {
            throw new \RuntimeException('Wallet payments are not enabled.');
        }

        return DB::transaction(function () use ($booking, $customer) {
            $locked = HallBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if (! $locked->balanceOpen()) {
                throw new \RuntimeException('There is nothing to pay on this booking now.');
            }
            $amount = $locked->balanceLater();
            app(WalletService::class)->debit($customer, $amount, reason: "Hall booking {$locked->code}: balance", ref: "hall_booking_balance:{$locked->id}:wallet-payment");
            $payment = Payment::create([
                'hall_booking_id' => $locked->id, 'purpose' => 'hall_booking', 'amount' => $amount,
                'gateway' => 'wallet', 'status' => 'captured', 'captured_at' => now(),
            ]);
            $locked->forceFill(['balance_payment_id' => $payment->id])->save();
            $this->balanceReceived($locked, $amount);

            return $locked->fresh();
        });
    }

    /** Webhook capture of a balance payment. Booking ended meanwhile, or already paid → money back in full. */
    private function markBalancePaid(HallBooking $booking, Payment $payment): void
    {
        $ok = DB::transaction(function () use ($booking, $payment) {
            $locked = HallBooking::whereKey($booking->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->balanceOpen()) {
                return false;
            }
            $this->balanceReceived($locked, (float) $payment->amount);

            return true;
        });
        if (! $ok) {
            $this->refundPaymentInFull($booking->fresh(), $payment, 'balance not needed any more');
        }
    }

    private function balanceReceived(HallBooking $booking, float $amount): void
    {
        $booking->forceFill([
            'balance_paid_at' => now(),
            'paid_amount' => round((float) $booking->paid_amount + $amount, 2),
            'deposit_status' => (float) $booking->deposit_amount > 0 ? 'held' : 'none',
        ])->save();
        DB::afterCommit(fn () => $this->notifyCustomer($booking->fresh(['hall', 'customer']), 'balance_paid', $amount));
    }

    /** A reminder from 3 days before the due date, once a day, until paid (scheduled). */
    public function remindBalance(): int
    {
        $count = 0;
        $today = now(app(TimezoneResolver::class)->platformTimezone())->toDateString();
        HallBooking::with(['hall', 'customer'])->where('status', 'confirmed')->whereNull('balance_paid_at')->where('paid_in_full', false)
            ->whereNotNull('balance_due_on')->where('balance_due_on', '<=', Carbon::parse($today)->addDays(3)->toDateString())
            ->where(fn ($q) => $q->whereNull('balance_reminded_on')->orWhere('balance_reminded_on', '<', $today))
            ->orderBy('id')->limit(200)->get()
            ->each(function (HallBooking $b) use ($today, &$count) {
                $b->forceFill(['balance_reminded_on' => $today])->save();
                $this->notifyCustomer($b, 'balance_due', $b->balanceLater());
                $count++;
            });

        return $count;
    }

    /** Balance still unpaid two days after it was due: cancelled under the normal cancellation rule (scheduled). */
    public function cancelUnpaidBalances(): int
    {
        $count = 0;
        $today = now(app(TimezoneResolver::class)->platformTimezone())->toDateString();
        HallBooking::where('status', 'confirmed')->whereNull('balance_paid_at')->where('paid_in_full', false)
            ->whereNotNull('balance_due_on')->where('balance_due_on', '<', Carbon::parse($today)->subDays(self::BALANCE_GRACE_DAYS)->toDateString())
            ->orderBy('id')->limit(100)->pluck('id')
            ->each(function (int $id) use (&$count) {
                try {
                    $this->cancelByCustomerRule(HallBooking::findOrFail($id), null, 'Balance not paid in time');
                    $count++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return $count;
    }

    // ─────────────────────────── R3: cancellation ───────────────────────────

    /**
     * What the customer gets back if they cancel now (Hall Settings rule). Waiting for the hall: everything. Confirmed:
     * at least full-days before → everything less the cancellation fee; at least half-days → half the advance kept;
     * closer → the advance kept. The deposit always comes back. What is kept (not the fee) goes to the owner.
     *
     * @return array{refund: float, kept: float, fee: float, days: int}
     */
    public static function cancelQuote(HallBooking $booking): array
    {
        $booking->loadMissing('hall.franchise');
        $paid = (float) $booking->paid_amount - (float) $booking->refunded_amount;
        if ($booking->status === 'awaiting_owner') {
            return ['refund' => round($paid, 2), 'kept' => 0.0, 'fee' => 0.0, 'days' => 0];
        }
        $scope = self::scope($booking->hall);
        $tz = self::tz($booking->hall);
        $deposit = $booking->deposit_status === 'held' ? (float) $booking->deposit_amount : 0.0;
        $forHall = max(0, $paid - $deposit);
        $days = (int) now($tz)->startOfDay()->diffInDays(Carbon::parse($booking->event_date->toDateString(), $tz)->startOfDay(), false);
        $advance = min($forHall, (float) $booking->advance_amount);
        $fee = 0.0;
        $kept = 0.0;
        if ($days >= (int) HallSettings::get('hall.cancel_full_days', $scope)) {
            $fee = min($forHall, (float) HallSettings::get('hall.cancel_fee', $scope));
        } elseif ($days >= (int) HallSettings::get('hall.cancel_half_days', $scope)) {
            $kept = round($advance / 2, 2);
        } else {
            $kept = $advance;
        }

        return ['refund' => round($forHall - $fee - $kept + $deposit, 2), 'kept' => round($kept, 2), 'fee' => round($fee, 2), 'days' => $days];
    }

    /** The customer cancels (waiting for the hall, or confirmed before the event starts). Returns the refund. */
    public function customerCancel(HallBooking $booking, User $customer, string $reason = ''): float
    {
        if ($booking->customer_id !== $customer->id) {
            throw new \RuntimeException('This is not your booking.');
        }

        return $this->cancelByCustomerRule($booking, $customer, trim($reason) ?: 'Cancelled by the customer');
    }

    private function cancelByCustomerRule(HallBooking $booking, ?User $by, string $reason): float
    {
        $refunded = 0.0;
        $done = DB::transaction(function () use ($booking, $by, $reason, &$refunded) {
            $locked = HallBooking::with(['hall.franchise', 'customer'])->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['awaiting_owner', 'confirmed'], true) || $locked->starts_at->isPast()) {
                throw new \RuntimeException('This booking can no longer be cancelled.');
            }
            $q = self::cancelQuote($locked);
            $refunded = $this->refund($locked, $q['refund'], $reason, 'cancel');
            $heldDeposit = $locked->deposit_status === 'held';
            $locked->forceFill([
                'status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => mb_substr($reason, 0, 500), 'cancelled_by' => $by?->id,
                'refunded_amount' => round((float) $locked->refunded_amount + $refunded, 2), 'kept_amount' => $q['kept'],
                'payment_status' => $q['kept'] + $q['fee'] > 0 ? 'partially_refunded' : 'refunded',
                'deposit_status' => $heldDeposit ? 'returned' : $locked->deposit_status,
                'deposit_returned_at' => $heldDeposit ? now() : $locked->deposit_returned_at,
            ])->save();
            ActivityLogger::logModel($by, $locked, 'Hall booking cancelled under the cancellation rule', $q + ['reason' => $reason, 'refunded' => $refunded]);

            return $locked;
        });
        $b = $done->fresh(['hall.provider.user', 'customer']);
        $this->notifyCustomer($b, 'cancelled', $refunded);
        $this->notifyOwner($b, 'cancelled');

        return $refunded;
    }

    /** The owner (or the team) cancels a confirmed booking: the customer always gets everything back. */
    public function ownerCancel(HallBooking $booking, User $by, string $reason): float
    {
        $refunded = 0.0;
        $done = DB::transaction(function () use ($booking, $by, $reason, &$refunded) {
            $locked = HallBooking::with('customer')->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'confirmed' || $locked->starts_at->isPast()) {
                throw new \RuntimeException('Only a confirmed booking that has not started can be cancelled.');
            }
            $refunded = $this->refund($locked, (float) $locked->paid_amount - (float) $locked->refunded_amount, trim($reason), 'cancel');
            $heldDeposit = $locked->deposit_status === 'held';
            $locked->forceFill([
                'status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => mb_substr(trim($reason), 0, 500), 'cancelled_by' => $by->id,
                'refunded_amount' => round((float) $locked->refunded_amount + $refunded, 2), 'kept_amount' => 0, 'payment_status' => 'refunded',
                'deposit_status' => $heldDeposit ? 'returned' : $locked->deposit_status,
                'deposit_returned_at' => $heldDeposit ? now() : $locked->deposit_returned_at,
            ])->save();
            ActivityLogger::logModel($by, $locked, 'Hall booking cancelled by the hall / team: full refund', ['reason' => $reason, 'refunded' => $refunded]);

            return $locked;
        });
        $this->notifyCustomer($done->fresh(['hall', 'customer']), 'hall_cancelled', $refunded);

        return $refunded;
    }

    // ─────────────────────────── R3: after the event ───────────────────────────

    /**
     * Scheduled: (1) confirmed bookings past the event + Hall Settings' payout hours → completed, the owner paid (after
     * commission); (2) a cancelled booking that left money with the owner → paid the same way once its date passed;
     * (3) deposits held past Hall Settings' return hours with no damage reported → back to the customer.
     *
     * @return array{completed: int, paid_cancelled: int, deposits: int}
     */
    public function afterEvents(): array
    {
        $done = ['completed' => 0, 'paid_cancelled' => 0, 'deposits' => 0];
        $commissions = app(\App\Services\CommissionService::class);
        $paidAlready = \App\Models\Commission::whereNotNull('hall_booking_id')->select('hall_booking_id');

        HallBooking::with('hall.franchise')->where('status', 'confirmed')->where('ends_at', '<', now())->orderBy('id')->limit(200)->get()
            ->each(function (HallBooking $b) use ($commissions, &$done) {
                $hours = (int) HallSettings::get('hall.payout_hours', self::scope($b->hall));
                if ($b->ends_at->copy()->addHours($hours)->isFuture() || $b->balanceLater() > 0) {
                    return;
                }
                DB::transaction(function () use ($b, $commissions) {
                    $b->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
                    $commissions->applyForHallBooking($b);
                    ActivityLogger::logModel(null, $b, 'Hall booking completed; owner paid after commission');
                });
                $done['completed']++;
            });

        HallBooking::with('hall.franchise')->where('status', 'cancelled')->where('kept_amount', '>', 0)->where('ends_at', '<', now())
            ->whereNotIn('id', $paidAlready)->orderBy('id')->limit(200)->get()
            ->each(function (HallBooking $b) use ($commissions, &$done) {
                $hours = (int) HallSettings::get('hall.payout_hours', self::scope($b->hall));
                if ($b->ends_at->copy()->addHours($hours)->isFuture()) {
                    return;
                }
                $commissions->applyForHallBooking($b);
                $done['paid_cancelled']++;
            });

        HallBooking::with(['hall.franchise', 'customer'])->where('deposit_status', 'held')->whereIn('status', ['completed', 'confirmed'])->where('ends_at', '<', now())
            ->orderBy('id')->limit(200)->get()
            ->each(function (HallBooking $b) use (&$done) {
                $hours = (int) HallSettings::get('hall.deposit_refund_hours', self::scope($b->hall));
                if ($b->ends_at->copy()->addHours($hours)->isFuture()) {
                    return;
                }
                $sent = DB::transaction(function () use ($b) {
                    $locked = HallBooking::with('customer')->whereKey($b->id)->lockForUpdate()->firstOrFail();
                    if ($locked->deposit_status !== 'held') {
                        return 0.0;
                    }
                    $sent = $this->refund($locked, (float) $locked->deposit_amount, 'deposit returned', 'deposit');
                    $locked->forceFill(['deposit_status' => 'returned', 'deposit_returned_at' => now(),
                        'refunded_amount' => round((float) $locked->refunded_amount + $sent, 2)])->save();

                    return $sent;
                });
                if ($sent > 0) {
                    $this->notifyCustomer($b->fresh(['hall', 'customer']), 'deposit_returned', $sent);
                    $done['deposits']++;
                }
            });

        return $done;
    }

    /** Can the owner still report damage? After the event starts, until the deposit goes back. */
    public static function damageOpen(HallBooking $booking): bool
    {
        if ($booking->deposit_status !== 'held' || ! in_array($booking->status, ['confirmed', 'completed'], true) || $booking->starts_at->isFuture()) {
            return false;
        }
        $booking->loadMissing('hall.franchise');
        $hours = (int) HallSettings::get('hall.deposit_refund_hours', self::scope($booking->hall));

        return $booking->ends_at->copy()->addHours($hours)->isFuture();
    }

    /**
     * The owner reports damage and asks for part (or all) of the deposit: the deposit is held and a claim waits in
     * Admin → Damage claims, decided under the money approval rules (HallDamageClaimService).
     */
    public function reportDamage(HallBooking $booking, User $owner, string $note, ?float $asked = null): HallBooking
    {
        if (! self::damageOpen($booking)) {
            throw new \RuntimeException('Damage can be reported only after the event starts and before the deposit goes back.');
        }
        $asked = $asked === null ? (float) $booking->deposit_amount : $asked;
        if ($asked <= 0 || $asked > (float) $booking->deposit_amount) {
            throw new \RuntimeException('Ask for between ₹1 and the deposit (₹'.number_format((float) $booking->deposit_amount).').');
        }
        DB::transaction(function () use ($booking, $owner, $note, $asked) {
            $booking->forceFill(['deposit_status' => 'claimed', 'damage_note' => mb_substr(trim($note), 0, 1000), 'damage_reported_at' => now()])->save();
            app(HallDamageClaimService::class)->open($booking, $asked, $note);
            ActivityLogger::logModel($owner, $booking, 'Damage reported by the hall: deposit held for the team', ['note' => trim($note), 'asked' => $asked]);
        });

        return $booking->fresh();
    }

    /** R4: part of what was paid back to the customer (used when a damage claim is settled). */
    public function refundPart(HallBooking $booking, float $amount, string $why, string $kind): float
    {
        return $this->refund($booking, $amount, $why, $kind);
    }

    /** R4: tell both sides how a damage claim ended. */
    public function notifyDamageSettled(HallBooking $booking, float $hallAmount, float $back): void
    {
        $this->notifyCustomer($booking, 'deposit_settled', $back);
        $owner = $booking->hall?->provider?->user;
        if ($owner) {
            rescue(fn () => $owner->notify(new \App\Notifications\HallBookingNotification('owner_damage_settled', $booking, $this->channels($booking), $hallAmount)));
        }
    }

    private function channels(HallBooking $booking): array
    {
        return \App\Notifications\Support\ChannelResolver::resolve(array_filter(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]));
    }

    private function notifyCustomer(HallBooking $booking, string $what, ?float $amount = null): void
    {
        // A message that cannot be sent (mail server down) is logged and never stops the booking.
        if ($booking->customer) {
            rescue(fn () => $booking->customer->notify(new \App\Notifications\HallBookingNotification($what, $booking, $this->channels($booking), $amount)));
        }
    }

    private function notifyOwner(HallBooking $booking, string $what): void
    {
        $owner = $booking->hall?->provider?->user;
        if ($owner) {
            rescue(fn () => $owner->notify(new \App\Notifications\HallBookingNotification('owner_'.$what, $booking, $this->channels($booking))));
        }
    }

    /** The cancellation rule in one customer-facing line (Hall Settings). Cancelling itself comes with R3. */
    public static function refundLine(Hall $hall): string
    {
        $scope = self::scope($hall);
        $symbol = Setting::get('locale.currency_symbol', '₹');

        return \App\Support\SiteText::get('hall.book.refund_rule', [
            'full' => (int) HallSettings::get('hall.cancel_full_days', $scope),
            'fee' => $symbol.number_format((float) HallSettings::get('hall.cancel_fee', $scope)),
            'half' => (int) HallSettings::get('hall.cancel_half_days', $scope),
        ]);
    }
}
