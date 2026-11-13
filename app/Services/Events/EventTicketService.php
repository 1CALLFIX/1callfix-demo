<?php

namespace App\Services\Events;

use App\Contracts\PaymentGateway;
use App\Exceptions\ModuleNotActiveException;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\EventTicket;
use App\Models\EventTicketType;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\ModuleActivationService;
use App\Services\WalletService;
use App\Support\EventSettings;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;

/**
 * Events E2 (owner 2026-11-13) — buying tickets. Online (Razorpay) or wallet only; no cash and no benefit of any kind
 * (no coupon on tickets yet). No payment, no ticket: an online order holds its tickets for Event Settings' unpaid hold;
 * the Razorpay capture WEBHOOK (never the browser) marks it paid and issues the tickets. A capture that arrives after
 * the hold ran out, or a second capture, is refunded in full. Wallet pays at once (the customer's own money).
 *
 * Prices: each ticket's GST comes from the Event Settings bands on its own price (blank bands = nothing can be sold);
 * the booking fee is per ticket. The totals the customer sees (quote) are the totals charged.
 */
class EventTicketService
{
    public function __construct(private PaymentGateway $gateway)
    {
    }

    /** Settings scope of an event (zone → franchise → city → country). */
    public static function scope(Event $event): array
    {
        return array_filter([
            'zone_id' => $event->zone_id, 'franchise_id' => $event->franchise_id,
            'city_id' => $event->franchise?->city_id, 'country_id' => $event->franchise?->country_id,
        ]);
    }

    /** Online sales stop this many minutes before the start (Event Settings). */
    public static function salesCloseAt(Event $event): \Illuminate\Support\Carbon
    {
        return $event->starts_at->copy()->subMinutes((int) EventSettings::get('event.sales_close_minutes', self::scope($event)));
    }

    /** On sale now: approved, its organiser approved and switched on, before sales close. */
    public static function onSale(Event $event): bool
    {
        return $event->status === 'approved' && $event->organiser?->approval_status === 'approved' && $event->organiser?->is_active
            && self::salesCloseAt($event)->isFuture();
    }

    public static function typeOnSale(EventTicketType $type): bool
    {
        return $type->is_active && $type->left() > 0 && ($type->sale_ends_at === null || $type->sale_ends_at->isFuture());
    }

    /**
     * The bill for these quantities (ticket type id => how many).
     *
     * @return array{lines: list<array{type: EventTicketType, quantity: int, line_total: float, gst_percent: float|null, gst: float}>, tickets: int, subtotal: float, gst: float, fee: float, total: float, taxes_known: bool}
     */
    public static function quote(Event $event, array $quantities): array
    {
        $scope = self::scope($event);
        $feeEach = (float) (EventSettings::get('event.booking_fee_per_ticket', $scope) ?? 0);
        $types = $event->ticketTypes->keyBy('id');
        $lines = [];
        $taxesKnown = true;
        foreach ($quantities as $typeId => $qty) {
            $qty = (int) $qty;
            $type = $types->get((int) $typeId);
            if ($type === null || $qty < 1) {
                continue;
            }
            $lineTotal = round((float) $type->price * $qty, 2);
            $percent = EventSettings::gstPercentFor((float) $type->price);
            $taxesKnown = $taxesKnown && $percent !== null;
            $lines[] = ['type' => $type, 'quantity' => $qty, 'line_total' => $lineTotal, 'gst_percent' => $percent,
                'gst' => round($lineTotal * (float) $percent / 100, 2)];
        }
        $tickets = array_sum(array_column($lines, 'quantity'));
        $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
        $gst = round(array_sum(array_column($lines, 'gst')), 2);
        $fee = round($feeEach * $tickets, 2);

        return ['lines' => $lines, 'tickets' => $tickets, 'subtotal' => $subtotal, 'gst' => $gst, 'fee' => $fee,
            'total' => round($subtotal + $gst + $fee, 2), 'taxes_known' => $taxesKnown];
    }

    /**
     * @param  array<int, int>  $quantities  ticket type id => how many
     *
     * @throws \RuntimeException with a customer-facing reason
     */
    public function buy(User $customer, Event $event, array $quantities, string $method, string $buyerName, ?string $buyerPhone = null): EventOrder
    {
        $event->loadMissing(['organiser', 'franchise', 'ticketTypes']);
        $scope = self::scope($event);
        if (! app(ModuleActivationService::class)->isActive(Modules::EVENTS, $scope)) {
            throw new ModuleNotActiveException(Modules::EVENTS);
        }
        if (! self::onSale($event)) {
            throw new \RuntimeException('Tickets for this event are not on sale.');
        }
        if (! in_array($method, ['online', 'wallet'], true)) {
            throw new \RuntimeException('Choose how to pay.');
        }

        $quantities = array_filter(array_map('intval', $quantities), fn ($q) => $q > 0);
        if ($quantities === []) {
            throw new \RuntimeException('Choose at least one ticket.');
        }
        $max = (int) EventSettings::get('event.max_tickets_per_order', $scope);
        if (array_sum($quantities) > $max) {
            throw new \RuntimeException("At most {$max} tickets in one order.");
        }
        foreach ($quantities as $typeId => $qty) {
            $type = $event->ticketTypes->firstWhere('id', (int) $typeId);
            if ($type === null || ! $type->is_active || ($type->sale_ends_at && $type->sale_ends_at->isPast())) {
                throw new \RuntimeException('One of these tickets is no longer on sale. Please choose again.');
            }
            if ($qty > $type->max_per_order) {
                throw new \RuntimeException("At most {$type->max_per_order} {$type->name} tickets in one order.");
            }
        }

        $quote = self::quote($event, $quantities);
        if (! $quote['taxes_known']) {
            throw new \RuntimeException('Ticket sales open soon for this event.');
        }

        $order = DB::transaction(function () use ($customer, $event, $quantities, $method, $buyerName, $buyerPhone, $quote, $scope) {
            // Take the tickets under a lock, in id order (two buyers never take the last ticket twice).
            $locked = EventTicketType::whereIn('id', array_keys($quantities))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($quantities as $typeId => $qty) {
                $type = $locked->get($typeId);
                if ($type === null || $type->left() < $qty) {
                    throw new \RuntimeException($type && $type->left() > 0
                        ? "Only {$type->left()} {$type->name} ".\Illuminate\Support\Str::plural('ticket', $type->left()).' left.'
                        : 'These tickets were just sold out. Please choose again.');
                }
                $type->increment('sold', $qty);
            }

            $order = EventOrder::create([
                'code' => EventOrder::newCode(),
                'event_id' => $event->id,
                'customer_id' => $customer->id,
                'franchise_id' => $event->franchise_id,
                'zone_id' => $event->zone_id,
                'buyer_name' => trim($buyerName),
                'buyer_phone' => filled($buyerPhone) ? trim($buyerPhone) : null,
                'status' => 'pending',
                'payment_method' => $method,
                'payment_status' => 'pending',
                'ticket_count' => $quote['tickets'],
                'subtotal' => $quote['subtotal'],
                'booking_fee' => $quote['fee'],
                'tax_amount' => $quote['gst'],
                'platform_collects_gst' => EventSettings::on('event.platform_collects_gst', $scope),
                'total' => $quote['total'],
                'payment_expires_at' => $method === 'online' ? now()->addMinutes((int) EventSettings::get('event.unpaid_hold_minutes', $scope)) : null,
            ]);
            foreach ($quote['lines'] as $line) {
                $order->items()->create([
                    'event_ticket_type_id' => $line['type']->id, 'name' => $line['type']->name, 'unit_price' => $line['type']->price,
                    'gst_percent' => (float) $line['gst_percent'], 'quantity' => $line['quantity'], 'admits' => $line['type']->admits,
                    'line_total' => $line['line_total'],
                ]);
            }

            if ($method === 'wallet') {
                if (Setting::get('payment.wallet_enabled', null, array_filter(['zone_id' => $event->zone_id, 'franchise_id' => $event->franchise_id])) !== '1') {
                    throw new \RuntimeException('Wallet payments are not enabled.');
                }
                app(WalletService::class)->debit($customer, (float) $order->total, reason: "Tickets {$order->code} ({$event->title})", ref: "event_order:{$order->id}:wallet-payment");
                Payment::create([
                    'event_order_id' => $order->id, 'purpose' => 'event_order', 'amount' => $order->total,
                    'gateway' => 'wallet', 'status' => 'captured', 'captured_at' => now(),
                ]);
                $this->confirm($order);
            }

            return $order;
        });

        return $order->fresh(['items', 'tickets']);
    }

    /** @return array{payment_id: int, razorpay_order_id: string, razorpay_key_id: ?string, amount: int, currency: string} */
    public function startOnlinePayment(EventOrder $order): array
    {
        if (! $order->isAwaitingOnlinePayment() || ($order->payment_expires_at && $order->payment_expires_at->isPast())) {
            throw new \RuntimeException('This order can no longer be paid. Please buy again.');
        }
        if (! $this->gateway->isConfigured()) {
            throw new \RuntimeException('Online payment is not available right now.');
        }
        if (Setting::get('payment.online_enabled', '1', array_filter(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id])) !== '1') {
            throw new \RuntimeException('Online payments are currently disabled.');
        }

        $gatewayOrder = $this->gateway->createRawOrder((float) $order->total, 'event-'.$order->id.'-'.now()->format('His'),
            ['event_order_id' => $order->id, 'purpose' => 'event_order']);
        $payment = Payment::create([
            'event_order_id' => $order->id, 'purpose' => 'event_order', 'amount' => $order->total,
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
     * Paid → tickets issued; the order cancelled meanwhile (not paid in time) or already paid → money back in full.
     */
    public function markPaid(Payment $payment): void
    {
        $order = $payment->eventOrder;
        if (! $order) {
            return;
        }

        $outcome = DB::transaction(function () use ($order) {
            $locked = EventOrder::whereKey($order->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'cancelled') {
                return 'cancelled';
            }
            if ($locked->payment_status === 'paid') {
                return 'already_paid';
            }
            $this->confirm($locked);

            return 'paid';
        });

        if ($outcome !== 'paid') {
            $this->refundPaymentInFull($order->fresh(), $payment, $outcome === 'cancelled' ? 'order was cancelled before the payment arrived' : 'paid twice');
        }
    }

    /** Paid: one ticket row per ticket, each with its own code for the gate. Call inside a transaction. */
    private function confirm(EventOrder $order): void
    {
        $order->forceFill(['status' => 'confirmed', 'payment_status' => 'paid', 'paid_at' => now(), 'payment_expires_at' => null])->save();
        foreach ($order->items()->get() as $item) {
            for ($i = 0; $i < $item->quantity; $i++) {
                EventTicket::create([
                    'event_order_id' => $order->id, 'event_id' => $order->event_id, 'event_ticket_type_id' => $item->event_ticket_type_id,
                    'code' => EventTicket::newCode(), 'admits' => $item->admits, 'status' => 'valid',
                ]);
            }
        }
    }

    private function refundPaymentInFull(EventOrder $order, Payment $payment, string $why): void
    {
        if ($payment->status !== 'captured' || (float) $payment->refunded_amount > 0) {
            return;
        }
        $this->gateway->refund($payment->gateway_payment_id, (float) $payment->amount, "Tickets {$order->code}: {$why}");
        $payment->forceFill(['refunded_amount' => $payment->amount, 'status' => 'refunded'])->save();
    }

    /**
     * events:expire-unpaid — online orders not paid within the hold are cancelled and their tickets go back on sale.
     * A payment that still arrives later is refunded by markPaid().
     */
    public function expireUnpaid(): int
    {
        $count = 0;
        EventOrder::query()->where('status', 'pending')->where('payment_status', 'pending')->where('payment_method', 'online')
            ->whereNotNull('payment_expires_at')->where('payment_expires_at', '<', now())
            ->orderBy('id')->limit(200)->pluck('id')
            ->each(function (int $id) use (&$count) {
                DB::transaction(function () use ($id, &$count) {
                    $o = EventOrder::with('items')->lockForUpdate()->find($id);
                    if (! $o || ! $o->isAwaitingOnlinePayment() || ! $o->payment_expires_at?->isPast()) {
                        return;
                    }
                    $o->forceFill(['status' => 'cancelled', 'cancellation_note' => 'Not paid in time'])->save();
                    foreach ($o->items as $item) {
                        EventTicketType::whereKey($item->event_ticket_type_id)->where('sold', '>=', $item->quantity)->decrement('sold', $item->quantity);
                    }
                    $count++;
                });
            });

        return $count;
    }
}
