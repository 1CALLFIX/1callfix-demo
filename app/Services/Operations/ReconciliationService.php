<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\HotelReservation;
use App\Models\LoyaltyPoint;
use App\Models\MarketplaceOrder;
use App\Models\ParcelOrder;
use App\Models\Payment;
use App\Models\PropertyReservation;
use App\Models\RentalReservation;
use App\Models\TaxiRide;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\AuthorizationService;
use Illuminate\Support\Collection;

/**
 * Reconciliation warnings (mission Phase 10, item 7; extended mission
 * Phase 15 — financial reconciliation audit). Strictly READ-ONLY —
 * identifies real inconsistencies by comparing existing tables against
 * each other, never mutates a financial record. Any actual repair is
 * explicitly out of scope here (a separately-authorized, auditable,
 * idempotent action, per the mission's own instruction) — this is the
 * "identify and report" half only.
 *
 * Admin Command Center mission, Finance Command Center phase (2026-08-20):
 * two real gaps found and closed here, both the same shape this session
 * already found and fixed once for DispatchHealthService (item 36):
 *
 * (1) detect() took no viewer at all — every one of its five original
 *     checks ran completely unscoped, unlike every other Finance screen in
 *     this codebase (Payments\Index, Commissions\Index, Payouts\Manage,
 *     WalletLedger\Index all apply AuthorizationService::scopeQuery()).
 *     operations.view defaults to super_admin-only but its own seeding
 *     migration explicitly documents it as "assignable to other roles via
 *     /admin/roles once a real business need (e.g. a dedicated Ops role)
 *     shows up" — the moment that happens, a franchise-scoped grant would
 *     have seen every OTHER franchise's paid-without-payment bookings,
 *     wallet mismatches, and negative loyalty balances too. Not exploited
 *     today (only super_admin holds the permission in practice), but the
 *     same real, evidence-based gap class this mission has repeatedly
 *     found and closed elsewhere — now closed here too, by threading the
 *     acting User through every check the same way DispatchHealthService
 *     and StuckBookingService already do on this same screen.
 *
 * (2) paidBookingsWithoutCapturedPayment()/completedBookingsWithoutCommission()
 *     only ever checked Booking. Parcel/Taxi/Property/Marketplace/Rental/
 *     Hotel all take real payments and pay real commission through the
 *     exact same Payment/Commission tables (see each model's own
 *     payments()/commission() relation, and CommissionService's own
 *     applyForParcelOrder()/applyForTaxiRide()/applyForPropertyReservation()/
 *     applyForMarketplaceOrder()/applyForRentalReservation()/
 *     applyForHotelReservation()) but were never covered here. New
 *     order_paid_without_captured_payment/order_completed_without_commission
 *     keys cover all six, kept SEPARATE from the original Booking-only keys
 *     (different model shapes/route names per vertical) rather than merged
 *     — the identical reasoning DispatchHealthService::exhaustedOrders()'s
 *     own docblock already gives for keeping stale_order_offers/
 *     exhausted_orders apart from stale_offers/exhausted_bookings.
 */
class ReconciliationService
{
    /** Order model => the status value meaning "done" for that vertical (see each Complete*Action/MarkParcelDeliveredAction). */
    private const ORDER_TERMINAL_STATUS = [
        ParcelOrder::class => 'delivered',
        TaxiRide::class => 'trip_completed',
        PropertyReservation::class => 'completed',
        MarketplaceOrder::class => 'completed',
        RentalReservation::class => 'completed',
        HotelReservation::class => 'completed',
    ];

    /** @return array{paid_bookings_without_captured_payment: Collection, completed_bookings_without_commission: Collection, wallet_balance_mismatches: Collection, wallet_topups_captured_without_credit: Collection, negative_loyalty_balances: Collection, order_paid_without_captured_payment: Collection, order_completed_without_commission: Collection} */
    public function detect(User $user): array
    {
        return [
            'paid_bookings_without_captured_payment' => $this->paidBookingsWithoutCapturedPayment($user),
            'completed_bookings_without_commission' => $this->completedBookingsWithoutCommission($user),
            'wallet_balance_mismatches' => $this->walletBalanceMismatches($user),
            'wallet_topups_captured_without_credit' => $this->walletTopupsCapturedWithoutCredit($user),
            'negative_loyalty_balances' => $this->negativeLoyaltyBalances($user),
            'order_paid_without_captured_payment' => $this->orderPaidWithoutCapturedPayment($user),
            'order_completed_without_commission' => $this->orderCompletedWithoutCommission($user),
        ];
    }

    /** Every Orderable model (Booking included) carries zone_id/franchise_id directly, city/country via its own franchise -- the exact shape Bookings\Index/Payments\Index/DispatchHealthService's own orderScopeColumns() all already use. */
    private function orderScopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    /** For models reached only through their owning user (Wallet, LoyaltyPoint, a wallet_topup Payment) -- same shape WalletLedger\Index's own baseQuery() already uses. */
    private function userScopeColumns(): array
    {
        return ['zone_id' => 'user.zone_id', 'franchise_id' => 'user.franchise_id', 'city_id' => 'user.franchise.city_id', 'country_id' => 'user.franchise.country_id'];
    }

    /** A booking marked payment_status='paid' should always have a matching captured Payment row -- if it doesn't, either the webhook never landed or something wrote payment_status directly. */
    private function paidBookingsWithoutCapturedPayment(User $user): Collection
    {
        return app(AuthorizationService::class)
            ->scopeQuery(Booking::query(), $user, 'operations.view', $this->orderScopeColumns())
            ->where('payment_status', 'paid')
            ->whereDoesntHave('payment', fn ($q) => $q->where('status', 'captured'))
            ->with('customer')
            ->latest('id')
            ->limit(100)
            ->get();
    }

    /** CompleteBookingAction always calls CommissionService::applyForBooking() -- a completed booking with no commission row means that step never ran (or ran and was rolled back oddly). */
    private function completedBookingsWithoutCommission(User $user): Collection
    {
        return app(AuthorizationService::class)
            ->scopeQuery(Booking::query(), $user, 'operations.view', $this->orderScopeColumns())
            ->where('status', 'completed')
            ->whereDoesntHave('commission')
            ->with('customer', 'provider.user')
            ->latest('id')
            ->limit(100)
            ->get();
    }

    /** Same check as paidBookingsWithoutCapturedPayment(), generalized across the six non-Booking Orderable verticals -- see this class's own docblock, finding (2). */
    private function orderPaidWithoutCapturedPayment(User $user): Collection
    {
        $authz = app(AuthorizationService::class);
        $columns = $this->orderScopeColumns();
        $results = collect();

        foreach (array_keys(self::ORDER_TERMINAL_STATUS) as $orderClass) {
            $rows = $authz->scopeQuery($orderClass::query(), $user, 'operations.view', $columns)
                ->where('payment_status', 'paid')
                ->whereDoesntHave('payments', fn ($q) => $q->where('status', 'captured'))
                ->with('customer')
                ->latest('id')
                ->limit(100)
                ->get();

            $results = $results->merge($rows);
        }

        return $results->sortByDesc('id')->values();
    }

    /** Same check as completedBookingsWithoutCommission(), generalized across the six non-Booking Orderable verticals -- see this class's own docblock, finding (2). */
    private function orderCompletedWithoutCommission(User $user): Collection
    {
        $authz = app(AuthorizationService::class);
        $columns = $this->orderScopeColumns();
        $results = collect();

        foreach (self::ORDER_TERMINAL_STATUS as $orderClass => $terminalStatus) {
            $rows = $authz->scopeQuery($orderClass::query(), $user, 'operations.view', $columns)
                ->where('status', $terminalStatus)
                ->whereDoesntHave('commission')
                ->with('customer')
                ->latest('id')
                ->limit(100)
                ->get();

            $results = $results->merge($rows);
        }

        return $results->sortByDesc('id')->values();
    }

    /**
     * wallets.balance is a stored running total (WalletService keeps it in
     * sync under a row lock on every transaction) -- if it ever drifts from
     * SUM(wallet_transactions), something bypassed WalletService. Flags
     * mismatches beyond a tiny float-rounding tolerance.
     *
     * Mission Phase 18 (performance/scale audit) finding: this used to load
     * EVERY wallet, then run one additional `->transactions()->get()->sum()`
     * query PER wallet -- a real, unbounded N+1 (unlike this check's four
     * sibling checks, none of which have a per-row query loop) that would
     * mean 10,001 queries for 10,000 wallets on every single Operations/
     * Troubleshoot page load. Rewritten to one grouped SQL aggregate
     * (backed by the new wallet_transactions (status, wallet_id) index) --
     * same per-wallet comparison, same tolerance, same output shape.
     */
    private function walletBalanceMismatches(User $user): Collection
    {
        $ledgerSums = WalletTransaction::query()
            ->where('status', 'successful')
            ->selectRaw('wallet_id, SUM(CASE WHEN is_credit = 1 THEN amount ELSE -amount END) as ledger_sum')
            ->groupBy('wallet_id')
            ->pluck('ledger_sum', 'wallet_id');

        return app(AuthorizationService::class)
            ->scopeQuery(Wallet::query(), $user, 'operations.view', $this->userScopeColumns())
            ->with('user')
            ->get()
            ->map(fn (Wallet $wallet) => [
                'wallet' => $wallet,
                'stored_balance' => (float) $wallet->balance,
                'ledger_sum' => round((float) ($ledgerSums[$wallet->id] ?? 0), 2),
            ])
            ->filter(fn ($row) => abs($row['stored_balance'] - $row['ledger_sum']) > 0.01)
            ->values();
    }

    /**
     * Phase 15 finding: RazorpayWebhookHandler::handleCaptured() commits
     * `Payment.status = 'captured'` inside its own DB::transaction(), then
     * calls WalletTopUpService::creditWalletForCapturedTopUp() (which
     * itself opens a SEPARATE transaction via WalletService) as a later,
     * non-atomic step. If the process dies between those two commits, a
     * customer's Razorpay payment is captured with no matching wallet
     * credit ever landing — real money paid, never delivered. No direct
     * Eloquent relation links Payment to the WalletTransaction it should
     * have produced (the ref is a deterministic "topup:{payment_id}"
     * string, not a FK), so this checks existence per-row rather than a
     * single join, same style walletBalanceMismatches() already uses.
     */
    private function walletTopupsCapturedWithoutCredit(User $user): Collection
    {
        return app(AuthorizationService::class)
            ->scopeQuery(Payment::query(), $user, 'operations.view', $this->userScopeColumns())
            ->where('purpose', 'wallet_topup')
            ->where('status', 'captured')
            ->with('user')
            ->latest('id')
            ->limit(100)
            ->get()
            ->reject(fn (Payment $payment) => WalletTransaction::where('ref', "topup:{$payment->id}")->exists())
            ->values();
    }

    /**
     * Phase 15 finding: LoyaltyService::redeem() used to check the
     * unlocked SUM() balance before opening its transaction — two
     * concurrent redemptions for the same user could both pass and both
     * credit the wallet, driving the aggregate ledger negative (fixed this
     * phase with a row lock, matching WalletService's own convention; this
     * check is the detection half, catching anything that slipped through
     * before the fix or via a future bypass). Mirrors
     * LoyaltyService::balance()'s own expiry-aware SUM() exactly.
     */
    private function negativeLoyaltyBalances(User $user): Collection
    {
        return app(AuthorizationService::class)
            ->scopeQuery(LoyaltyPoint::query(), $user, 'operations.view', $this->userScopeColumns())
            ->select('user_id')
            ->selectRaw('SUM(points) as balance')
            ->where(function ($q) {
                $q->where('points', '<', 0)
                    ->orWhereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->groupBy('user_id')
            ->having('balance', '<', 0)
            ->with('user')
            ->get();
    }
}
