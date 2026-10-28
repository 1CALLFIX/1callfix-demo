<?php

namespace App\Services\Marketplace;

use App\Contracts\PaymentGateway;
use App\Models\Franchise;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceReturn;
use App\Models\MarketplaceSettlement;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AuthorizationService;
use App\Services\WalletService;
use App\Support\MarketplaceSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E-commerce Phase 3b — shop returns. Owner rules: within the return window (Shop Settings, default 3 days) of the
 * order completing, damaged or wrong item only, with a photo. An admin accepts or declines; the seller confirms the
 * item came back; then the refund enters the MANUAL MONEY ACTIONS approval model (CLAUDE.md), the same shape as
 * BookingDisputeService:
 *  - permission  marketplace_returns.refund (Super Admin always), checked here, server-side; deciding the return
 *                itself is marketplace_returns.manage;
 *  - scope       franchise holders act only on their franchise's returns; global/country/city holders are HQ;
 *  - limits      refund.shop_return.franchise_limit / hq_limit (rupees, null = that level cannot approve);
 *  - maker-checker  refund.shop_return.dual_approval_above (null = off);
 *  - escalation  refund.shop_return.escalate_after_hours (null = off), franchise -> HQ -> Super Admin;
 *  - reason required, row-locked, idempotent (unique wallet ref / stored gateway refund id), never automatic.
 * Money: online orders are refunded to the original payment; wallet and cash orders to the wallet (the HQ ledger).
 * The refund comes out of the order's held settlement — seller, platform and franchise goods shares cut in the same
 * proportion — and the seller is never paid while a return is open (releaseDue skips it).
 */
class MarketplaceReturnService
{
    public const MANAGE = 'marketplace_returns.manage';

    public const REFUND = 'marketplace_returns.refund';

    public const LEVEL_FRANCHISE = 1;

    public const LEVEL_HQ = 2;

    public const LEVEL_SUPER = 3;

    public const FRANCHISE_LIMIT_KEY = 'refund.shop_return.franchise_limit';

    public const HQ_LIMIT_KEY = 'refund.shop_return.hq_limit';

    public const DUAL_APPROVAL_KEY = 'refund.shop_return.dual_approval_above';

    public const ESCALATE_HOURS_KEY = 'refund.shop_return.escalate_after_hours';

    public function __construct(private AuthorizationService $authz, private WalletService $wallet, private PaymentGateway $gateway)
    {
    }

    // ============================== customer ==============================

    /** Why this customer cannot ask for a return on this order now, or null if they can. */
    public function cannotRequest(MarketplaceOrder $order, int $customerId): ?string
    {
        if ($order->customer_id !== $customerId) {
            return 'This is not your order.';
        }
        if ($order->status !== 'completed' || ! $order->completed_at) {
            return 'A return can be asked for once the order is delivered or collected.';
        }
        $days = (int) MarketplaceSettings::get('marketplace.return_window_days');
        if ($order->completed_at->copy()->addDays($days)->isPast()) {
            return "The {$days}-day return window has closed.";
        }
        if (MarketplaceReturn::where('marketplace_order_id', $order->id)->where('status', '!=', MarketplaceReturn::DECLINED)->exists()) {
            return 'A return is already open for this order.';
        }

        return null;
    }

    /**
     * @param  array<int, int>  $quantities  order item id => quantity to return
     * @param  list<string>  $photoPaths  stored on the public disk
     */
    public function request(MarketplaceOrder $order, User $customer, string $reason, string $description, array $quantities, array $photoPaths): MarketplaceReturn
    {
        if (($why = $this->cannotRequest($order, $customer->id)) !== null) {
            throw new \RuntimeException($why);
        }
        if (! array_key_exists($reason, MarketplaceReturn::REASONS)) {
            throw new \RuntimeException('Returns are for damaged or wrong items only.');
        }
        if ($photoPaths === []) {
            throw new \RuntimeException('Add at least one photo of the item.');
        }

        $items = [];
        $amount = 0.0;
        foreach ($order->items as $item) {
            $qty = min((int) ($quantities[$item->id] ?? 0), (int) $item->quantity);
            if ($qty > 0) {
                $items[] = ['order_item_id' => $item->id, 'quantity' => $qty];
                $amount += round((float) $item->line_total / max(1, (int) $item->quantity) * $qty, 2);
            }
        }
        if ($items === []) {
            throw new \RuntimeException('Choose the item(s) you are returning.');
        }

        $return = MarketplaceReturn::create([
            'marketplace_order_id' => $order->id, 'franchise_id' => $order->franchise_id, 'store_id' => $order->store_id,
            'customer_id' => $customer->id, 'reason' => $reason, 'description' => trim($description), 'photos' => $photoPaths,
            'items' => $items, 'status' => MarketplaceReturn::REQUESTED, 'refund_amount' => round($amount, 2),
            'refund_destination' => $order->payment_method === 'online' ? 'original' : 'wallet',
        ]);
        ActivityLogger::logModel($customer, $return, "Shop return requested (#{$return->id})", ['order' => $order->code, 'reason' => $reason, 'amount' => $return->refund_amount]);

        return $return;
    }

    // ============================== who may act ==============================

    private function scopeFor(?int $franchiseId): array
    {
        if (! $franchiseId) {
            return [];
        }
        $franchise = Franchise::find($franchiseId);

        return array_filter(['franchise_id' => $franchiseId, 'city_id' => $franchise?->city_id, 'country_id' => $franchise?->country_id]);
    }

    /** 3 = Super Admin, 2 = HQ grant, 1 = franchise grant, null = no such permission over this franchise. */
    public function levelOf(User $user, ?int $franchiseId, string $permission = self::REFUND): ?int
    {
        if ($user->role === 'super_admin') {
            return self::LEVEL_SUPER;
        }
        $scope = $this->scopeFor($franchiseId);
        $best = null;
        foreach ($user->roleAssignments()->with('role.permissions')->get() as $assignment) {
            if (! $assignment->role->permissions->contains('slug', $permission)) {
                continue;
            }
            if (! $this->authz->scopeCovers($assignment->scope_type, $assignment->scope_id, $scope)) {
                continue;
            }
            $best = max($best ?? 0, $assignment->scope_type === 'franchise' ? self::LEVEL_FRANCHISE : self::LEVEL_HQ);
        }

        return $best;
    }

    public function canEnter(User $user): bool
    {
        return $this->authz->canAnywhere($user, self::MANAGE) || $this->authz->canAnywhere($user, self::REFUND);
    }

    /** Returns this user may see: Super Admin / global = all; franchise / city / country grants = their franchises. */
    public function visibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === 'super_admin') {
            return $query;
        }
        $ids = [];
        foreach ($user->roleAssignments()->with('role.permissions')->get() as $assignment) {
            if (! $assignment->role->permissions->contains(fn ($p) => in_array($p->slug, [self::MANAGE, self::REFUND], true))) {
                continue;
            }
            switch ($assignment->scope_type) {
                case 'global':
                    return $query;
                case 'franchise':
                    $ids[] = (int) $assignment->scope_id;
                    break;
                case 'city':
                    $ids = array_merge($ids, Franchise::where('city_id', $assignment->scope_id)->pluck('id')->all());
                    break;
                case 'country':
                    $ids = array_merge($ids, Franchise::where('country_id', $assignment->scope_id)->pluck('id')->all());
                    break;
            }
        }

        return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('franchise_id', array_unique($ids));
    }

    private function rupees(string $key): ?float
    {
        $value = Setting::get($key);

        return ($value === null || trim((string) $value) === '') ? null : round((float) $value, 2);
    }

    public function limit(int $level): ?float
    {
        return match ($level) {
            self::LEVEL_SUPER => (float) PHP_INT_MAX,
            self::LEVEL_HQ => $this->rupees(self::HQ_LIMIT_KEY),
            self::LEVEL_FRANCHISE => $this->rupees(self::FRANCHISE_LIMIT_KEY),
            default => null,
        };
    }

    private function canCover(?int $level, MarketplaceReturn $r): bool
    {
        $limit = $level === null ? null : $this->limit($level);

        return $limit !== null && (float) $r->refund_amount <= $limit;
    }

    private function needsDualApproval(MarketplaceReturn $r): bool
    {
        $threshold = $this->rupees(self::DUAL_APPROVAL_KEY);

        return $threshold !== null && (float) $r->refund_amount > $threshold;
    }

    /** 'decide' | 'receive' | 'request' | 'approve' | 'retry' | null — UI only; every action re-checks. */
    public function availableAction(MarketplaceReturn $r, User $user): ?string
    {
        if ($r->status === MarketplaceReturn::REQUESTED) {
            return $this->levelOf($user, $r->franchise_id, self::MANAGE) !== null ? 'decide' : null;
        }
        if ($r->status === MarketplaceReturn::ACCEPTED) {
            return $this->levelOf($user, $r->franchise_id, self::MANAGE) !== null ? 'receive' : null;
        }
        $level = $this->levelOf($user, $r->franchise_id);
        if ($level === null) {
            return null;
        }

        return match ($r->refund_status) {
            MarketplaceReturn::REFUND_AWAITING_REQUEST => 'request',
            MarketplaceReturn::REFUND_AWAITING_APPROVAL => ($user->id !== $r->refund_requested_by_id && $this->canCover($level, $r)) ? 'approve' : null,
            MarketplaceReturn::REFUND_FAILED => $this->canCover($level, $r) ? 'retry' : null,
            default => null,
        };
    }

    // ============================== decide / receive (no money moves) ==============================

    /** @return array{ok: bool, message: string} */
    public function decide(MarketplaceReturn $return, User $admin, bool $accept, string $note, ?float $amount = null): array
    {
        $note = trim($note);

        return DB::transaction(function () use ($return, $admin, $accept, $note, $amount) {
            $r = MarketplaceReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== MarketplaceReturn::REQUESTED) {
                return $this->no('This return has already been decided.');
            }
            if ($this->levelOf($admin, $r->franchise_id, self::MANAGE) === null) {
                return $this->no('You are not permitted to decide this return.');
            }
            if ($note === '') {
                return $this->no('A note is required.');
            }
            if ($accept && $amount !== null) {
                $cap = (float) MarketplaceOrder::whereKey($r->marketplace_order_id)->value('total_amount');
                if ($amount <= 0 || $amount > $cap) {
                    return $this->no('The refund must be more than 0 and at most the order total.');
                }
                $r->refund_amount = round($amount, 2);
            }

            $r->status = $accept ? MarketplaceReturn::ACCEPTED : MarketplaceReturn::DECLINED;
            $r->decided_by_id = $admin->id;
            $r->decision_note = $note;
            $r->decided_at = now();
            $r->save();
            ActivityLogger::logModel($admin, $r, 'Shop return '.($accept ? 'accepted' : 'declined')." (#{$r->id})", ['note' => $note, 'amount' => $r->refund_amount]);

            return ['ok' => true, 'message' => $accept ? 'Return accepted. Once the item is back at the shop, the refund can be requested.' : 'Return declined. The customer sees your note.'];
        });
    }

    /**
     * The item is back at the shop: by the seller (their own shop only) or an admin with marketplace_returns.manage.
     * Opens the refund for a request. @return array{ok: bool, message: string}
     */
    public function markReceived(MarketplaceReturn $return, User $actor): array
    {
        return DB::transaction(function () use ($return, $actor) {
            $r = MarketplaceReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== MarketplaceReturn::ACCEPTED) {
                return $this->no('Only an accepted return can be marked received.');
            }
            $isSeller = $r->store()->whereHas('provider', fn ($q) => $q->where('user_id', $actor->id))->exists();
            if (! $isSeller && $this->levelOf($actor, $r->franchise_id, self::MANAGE) === null) {
                return $this->no('You are not permitted to mark this return received.');
            }

            $r->status = MarketplaceReturn::RECEIVED;
            $r->received_at = now();
            $r->refund_status = MarketplaceReturn::REFUND_AWAITING_REQUEST;
            $r->save();
            ActivityLogger::logModel($actor, $r, "Shop return item received back (#{$r->id})", ['by' => $isSeller ? 'seller' : 'admin']);

            return ['ok' => true, 'message' => 'Marked received. The refund is now waiting in the approval queue.'];
        });
    }

    // ============================== refund (approval model) ==============================

    /** @return array{ok: bool, message: string} */
    public function requestRefund(MarketplaceReturn $return, User $actor, string $reason): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($return, $actor, $reason) {
            $r = MarketplaceReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($r->refund_status !== MarketplaceReturn::REFUND_AWAITING_REQUEST) {
                return $this->no('This refund is not waiting for a request.');
            }
            $level = $this->levelOf($actor, $r->franchise_id);
            if ($level === null) {
                return $this->no('You are not permitted to refund this return.');
            }
            if ($reason === '') {
                return $this->no('A reason is required.');
            }

            $r->refund_requested_by_id = $actor->id;
            $r->refund_request_reason = $reason;
            $r->refund_requested_at = now();
            ActivityLogger::logModel($actor, $r, "Shop return refund requested (#{$r->id})", ['amount' => $r->refund_amount, 'reason' => $reason, 'level' => $level]);

            if (! $this->needsDualApproval($r) && $this->canCover($level, $r)) {
                return $this->execute($r, $actor, $reason);
            }

            $r->refund_status = MarketplaceReturn::REFUND_AWAITING_APPROVAL;
            $r->save();

            return ['ok' => true, 'message' => 'Request recorded. It now needs approval from a different user with a sufficient limit.'];
        });
    }

    /** @return array{ok: bool, message: string} */
    public function approveRefund(MarketplaceReturn $return, User $actor, string $reason): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($return, $actor, $reason) {
            $r = MarketplaceReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($r->refund_status !== MarketplaceReturn::REFUND_AWAITING_APPROVAL) {
                return $this->no('This refund is not awaiting approval.');
            }
            $level = $this->levelOf($actor, $r->franchise_id);
            if ($level === null) {
                return $this->no('You are not permitted to approve this refund.');
            }
            if ($actor->id === $r->refund_requested_by_id) {
                return $this->no('You cannot approve your own request. A different user must approve it.');
            }
            if (! $this->canCover($level, $r)) {
                return $this->no('Your approval limit does not cover this amount.');
            }
            if ($reason === '') {
                return $this->no('A reason is required.');
            }

            $r->refund_approved_by_id = $actor->id;
            $r->refund_approval_reason = $reason;
            $r->refund_approved_at = now();
            ActivityLogger::logModel($actor, $r, "Shop return refund approved (#{$r->id})", ['amount' => $r->refund_amount, 'reason' => $reason, 'requested_by_id' => $r->refund_requested_by_id]);

            return $this->execute($r, $actor, $reason);
        });
    }

    /** @return array{ok: bool, message: string} */
    public function rejectRefund(MarketplaceReturn $return, User $actor, string $reason): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($return, $actor, $reason) {
            $r = MarketplaceReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($r->refund_status !== MarketplaceReturn::REFUND_AWAITING_APPROVAL) {
                return $this->no('This refund is not awaiting approval.');
            }
            $level = $this->levelOf($actor, $r->franchise_id);
            if ($level === null || $actor->id === $r->refund_requested_by_id || ! $this->canCover($level, $r)) {
                return $this->no('You cannot reject this request (permission, own request, or above your limit).');
            }
            if ($reason === '') {
                return $this->no('A reason is required.');
            }
            ActivityLogger::logModel($actor, $r, "Shop return refund rejected (#{$r->id})", ['rejection_reason' => $reason, 'requested_by_id' => $r->refund_requested_by_id]);

            $r->refund_status = MarketplaceReturn::REFUND_AWAITING_REQUEST;
            $r->refund_requested_by_id = null;
            $r->refund_request_reason = null;
            $r->refund_requested_at = null;
            $r->refund_rejected_at = now();
            $r->escalation_level = 0;
            $r->last_escalated_at = null;
            $r->save();

            return ['ok' => true, 'message' => 'Request rejected. The refund is back awaiting a new request.'];
        });
    }

    /** @return array{ok: bool, message: string} */
    public function retry(MarketplaceReturn $return, User $actor): array
    {
        return DB::transaction(function () use ($return, $actor) {
            $r = MarketplaceReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($r->refund_status !== MarketplaceReturn::REFUND_FAILED) {
                return $this->no('Only a failed refund can be retried.');
            }
            if (! $this->canCover($this->levelOf($actor, $r->franchise_id), $r)) {
                return $this->no('You are not permitted to retry this refund, or it is above your limit.');
            }
            ActivityLogger::logModel($actor, $r, "Shop return refund retry (#{$r->id})");

            return $this->execute($r, $actor, $r->refund_approval_reason ?: (string) $r->refund_request_reason);
        });
    }

    /** Inside the caller's transaction with $r locked. A failure is recorded, not thrown, so that state commits. */
    private function execute(MarketplaceReturn $r, User $actor, string $reason): array
    {
        $order = MarketplaceOrder::with('customer')->find($r->marketplace_order_id);
        $amount = round((float) $r->refund_amount, 2);

        try {
            if ($r->refund_destination === 'original') {
                $this->refundToOriginalMethod($r, $order, $amount, $reason);
            } else {
                $this->wallet->credit(
                    $order->customer,
                    $amount,
                    reason: "Refund for returned items — order {$order->code}",
                    ref: "marketplace_order:{$order->id}:wallet-refund:return-{$r->id}",
                    actorId: $actor->id,
                );
            }
        } catch (\Throwable $e) {
            $r->refund_status = MarketplaceReturn::REFUND_FAILED;
            $r->refund_failure_message = mb_substr($e->getMessage(), 0, 1000);
            $r->save();
            ActivityLogger::logModel($actor, $r, "Shop return refund FAILED (#{$r->id})", ['amount' => $amount, 'error' => $e->getMessage()]);

            return $this->no('The refund could not be processed. Nothing was refunded; you can retry. Details are in the activity log.');
        }

        $r->refund_status = MarketplaceReturn::REFUND_DONE;
        $r->status = MarketplaceReturn::REFUNDED;
        $r->refunded_at = now();
        $r->refund_failure_message = null;
        $r->save();

        $order->forceFill(['payment_status' => $amount >= (float) $order->total_amount ? 'refunded' : 'partially_refunded'])->save();
        $this->takeFromSettlement($order, $amount, $actor, $r);

        ActivityLogger::logModel($actor, $r, "Shop return refunded (#{$r->id})", [
            'amount' => $amount, 'destination' => $r->refund_destination, 'requested_by_id' => $r->refund_requested_by_id, 'approved_by_id' => $r->refund_approved_by_id,
        ]);

        return ['ok' => true, 'message' => '₹'.number_format($amount, 2).($r->refund_destination === 'original'
            ? ' refunded to the customer\'s original payment method.' : ' credited to the customer\'s wallet.')];
    }

    private function refundToOriginalMethod(MarketplaceReturn $r, MarketplaceOrder $order, float $amount, string $reason): void
    {
        if ($r->refund_gateway_id) {
            return; // accepted by the gateway on an earlier attempt
        }
        $payment = Payment::where('marketplace_order_id', $order->id)->where('status', 'captured')->where('gateway', '!=', 'wallet')->latest('id')->first();
        if (! $payment || ! $payment->gateway_payment_id) {
            throw new \RuntimeException('No online payment found to refund to.');
        }
        $refundable = round((float) $payment->amount - (float) $payment->refunded_amount, 2);
        if ($amount > $refundable) {
            throw new \RuntimeException('Only '.number_format($refundable, 2).' of this payment can still be refunded.');
        }
        $refund = $this->gateway->refund($payment->gateway_payment_id, $amount, "Shop return #{$r->id}: {$reason}");
        $r->refund_gateway_id = is_array($refund) ? ($refund['id'] ?? 'accepted') : 'accepted';
        $payment->refunded_amount = round((float) $payment->refunded_amount + $amount, 2);
        $payment->save();
    }

    /**
     * The refund comes out of the goods money: seller, platform and franchise goods shares are cut in the same
     * proportion (refund / goods). Held earnings are simply reduced. A cash-at-pickup seller already holds the cash,
     * so their part of the refund is added to what they owe back.
     */
    private function takeFromSettlement(MarketplaceOrder $order, float $amount, User $actor, MarketplaceReturn $r): void
    {
        $row = MarketplaceSettlement::where('marketplace_order_id', $order->id)->lockForUpdate()->first();
        if (! $row || (float) $row->goods_amount <= 0) {
            return;
        }
        $f = min(1.0, $amount / (float) $row->goods_amount);
        $sellerCut = round((float) $row->seller_share * $f, 2);
        $platformCut = round((float) $row->goods_platform * $f, 2);
        $franchiseCut = round((float) $row->goods_franchise * $f, 2);

        if ($row->payment_mode === 'cash_pickup') {
            $row->cash_owed = round((float) $row->cash_owed - $platformCut - $franchiseCut + $amount, 2);
            $row->cash_franchise_portion = max(0, round((float) $row->cash_franchise_portion - $franchiseCut, 2));
            $row->cash_status = $row->cash_owed > (float) $row->cash_settled ? 'outstanding' : $row->cash_status;
        } elseif ($row->payment_mode === 'cash_delivery') {
            $row->cash_franchise_portion = max(0, round((float) $row->cash_franchise_portion - $franchiseCut, 2));
        }
        $row->seller_share = max(0, round((float) $row->seller_share - $sellerCut, 2));
        $row->goods_platform = round((float) $row->goods_platform - $platformCut, 2);
        $row->goods_franchise = max(0, round((float) $row->goods_franchise - $franchiseCut, 2));
        $row->save();

        if ($row->commission) {
            $row->commission->update([
                'provider_commission' => $row->seller_share,
                'franchise_commission' => round((float) $row->commission->franchise_commission - $franchiseCut, 2),
                'platform_commission' => round((float) $row->commission->platform_commission - $platformCut, 2),
            ]);
        }
        ActivityLogger::logModel($actor, $r, "Shop return #{$r->id}: settlement reduced", ['seller' => $sellerCut, 'platform' => $platformCut, 'franchise' => $franchiseCut, 'mode' => $row->payment_mode]);
    }

    private function no(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }

    // ============================== escalation ==============================

    public function escalateAfterHours(): ?int
    {
        $value = Setting::get(self::ESCALATE_HOURS_KEY);

        return ($value === null || trim((string) $value) === '' || (int) $value < 1) ? null : (int) $value;
    }

    public function handlerLevel(MarketplaceReturn $r): int
    {
        $franchiseLimit = $this->limit(self::LEVEL_FRANCHISE);
        $hqLimit = $this->limit(self::LEVEL_HQ);
        $amount = (float) $r->refund_amount;
        if ($r->franchise_id && $franchiseLimit !== null && $amount <= $franchiseLimit) {
            return self::LEVEL_FRANCHISE;
        }

        return ($hqLimit !== null && $amount <= $hqLimit) ? self::LEVEL_HQ : self::LEVEL_SUPER;
    }

    /** One level up per elapsed interval, once per level; audit-logged. @return int escalations recorded */
    public function escalateOverdue(): int
    {
        $hours = $this->escalateAfterHours();
        if ($hours === null) {
            return 0;
        }
        $raised = 0;
        MarketplaceReturn::whereIn('refund_status', MarketplaceReturn::REFUND_OPEN)->orderBy('id')->chunkById(200, function ($rows) use ($hours, &$raised) {
            foreach ($rows as $r) {
                $handler = $this->handlerLevel($r);
                $target = min(self::LEVEL_SUPER, $handler + intdiv((int) $r->refundClockStartedAt()->diffInHours(now()), $hours));
                if ($target <= $handler || $target <= $r->escalation_level) {
                    continue;
                }
                $r->update(['escalation_level' => $target, 'last_escalated_at' => now()]);
                ActivityLogger::logModel(null, $r, "Shop return refund #{$r->id} escalated to level {$target}", ['hours' => $hours]);
                $raised++;
            }
        });

        return $raised;
    }
}
