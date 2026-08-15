<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mission Phase 18 (performance/scale audit). Same method as
// 2026_08_12_003000_add_indexes_to_bookings_table.php: grepped real
// where()/whereIn()/groupBy() usage across app/ per table rather than
// indexing every status-like column on assumption. Every column below has
// a confirmed, unscoped-or-weakly-scoped real caller today; columns with
// only usage already narrowed by an existing indexed FK (e.g.
// partner_workers.status, always queried alongside its own unique
// (provider_id, field_worker_id) pair; booking_status_history.status,
// always queried alongside its own indexed booking_id, a handful of rows
// per booking) were deliberately left alone -- adding an index there would
// have no real query-plan benefit given the cardinality already in place.
//
// - payments (purpose, status): ReconciliationService::walletTopupsCapturedWithoutCredit()
//   filters purpose='wallet_topup' + status='captured' with no other scoping
//   column at all -- a real full-table-scan candidate as the payments table
//   grows. WalletTopUpService's own duplicate-topup guard filters the same pair.
// - bookings.payment_status: Booking::where('payment_status','paid') in
//   ReconciliationService::paidBookingsWithoutCapturedPayment() -- real usage
//   that postdates 2026_08_12_003000's own comment (written before Phase 15
//   added this call), which explicitly found no WHERE usage on this column
//   at the time and left it unindexed. That premise no longer holds.
// - wallet_transactions (status, wallet_id): backs the grouped-aggregate
//   rewrite of ReconciliationService::walletBalanceMismatches() (same
//   phase) -- WHERE status='successful' GROUP BY wallet_id, status leading
//   the composite so the equality filter narrows before grouping.
// - dispatch_attempts.status: DispatchHealthService's stale-offer detection
//   (Operations dashboard) AND the real booking-acceptance hot path
//   (AcceptBookingAction, AdminReassignBookingAction, ServiceMatchingJob)
//   all filter status='notified' with no other scoping column.
// - payouts.status: PaymentAccountService's in-flight-payout gate
//   (whereIn ['pending','processing']) on payment-account delete/verify,
//   filtered by payment_account_id but payout volume per account can still
//   grow large over a payee's lifetime.
// - referrals.status: ReferralService::expirePendingReferrals(), the
//   ExpireReferrals scheduled command's real per-run full-table scan
//   (status='pending' + expires_at <=now) -- exactly the kind of
//   ever-growing, repeatedly-scanned table this audit targets.
// - notification_logs.status: written on every single notification sent
//   app-wide (AppServiceProvider's listener) -- almost certainly the
//   highest-row-count table in the schema -- read via
//   NotificationLog::where('status','failed') on Operations/Health (every
//   page load) and NotificationCenter\Manage's own log filter.
// - franchises.status: Dashboard's per-request KPI query, Franchises\Manage
//   and NotificationCenter\Manage's status filters, AudienceResolver's
//   campaign-audience resolution -- all real, none scoped by anything else.
// - users (role, status): Customers\Index's role='customer' (+ optional
//   status) filter -- users is the single largest, fastest-growing table
//   in the whole schema (every customer/provider/worker/admin signup), and
//   had no index on either column before this.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['purpose', 'status'], 'payments_purpose_status_idx');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->index('payment_status', 'bookings_payment_status_idx');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->index(['status', 'wallet_id'], 'wallet_transactions_status_wallet_idx');
        });

        Schema::table('dispatch_attempts', function (Blueprint $table) {
            $table->index('status', 'dispatch_attempts_status_idx');
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->index('status', 'payouts_status_idx');
        });

        Schema::table('referrals', function (Blueprint $table) {
            $table->index('status', 'referrals_status_idx');
        });

        Schema::table('notification_logs', function (Blueprint $table) {
            $table->index('status', 'notification_logs_status_idx');
        });

        Schema::table('franchises', function (Blueprint $table) {
            $table->index('status', 'franchises_status_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index(['role', 'status'], 'users_role_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropIndex('payments_purpose_status_idx'));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropIndex('bookings_payment_status_idx'));
        Schema::table('wallet_transactions', fn (Blueprint $table) => $table->dropIndex('wallet_transactions_status_wallet_idx'));
        Schema::table('dispatch_attempts', fn (Blueprint $table) => $table->dropIndex('dispatch_attempts_status_idx'));
        Schema::table('payouts', fn (Blueprint $table) => $table->dropIndex('payouts_status_idx'));
        Schema::table('referrals', fn (Blueprint $table) => $table->dropIndex('referrals_status_idx'));
        Schema::table('notification_logs', fn (Blueprint $table) => $table->dropIndex('notification_logs_status_idx'));
        Schema::table('franchises', fn (Blueprint $table) => $table->dropIndex('franchises_status_idx'));
        Schema::table('users', fn (Blueprint $table) => $table->dropIndex('users_role_status_idx'));
    }
};
