<?php

namespace App\Services;

use App\Models\FieldWorker;
use App\Models\Franchise;
use App\Models\PaymentAccount;
use App\Models\Payout;
use App\Models\Provider;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PayoutStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\Kyc\KycWithdrawalPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns wallet balance into an actual money-movement request, auditable
 * end to end. Reuses WalletService for every balance change (no direct
 * balance mutation anywhere here) — see WalletService::applyTransaction()
 * for the row-locking/transaction guarantees this inherits.
 *
 * v1 scope, deliberately: there is no live payout-gateway integration
 * (Razorpay Route/similar) anywhere in this codebase, and building one
 * wasn't asked for or decided. The lifecycle below is the real, auditable
 * record-keeping half of disbursement — request holds the money out of the
 * payee's spendable wallet balance immediately (so it can't be
 * double-requested), an operator transfers it manually (bank/UPI, via the
 * payment_accounts row) outside this system, then records the outcome
 * here. `gateway_ref` stays free-text for that manual reference today; if
 * a real payout API gets integrated later, markPaid()'s caller is the only
 * thing that changes — the ledger shape underneath doesn't.
 */
class PayoutService
{
    public function __construct(
        private WalletService $walletService,
        private KycWithdrawalPolicyService $kycWithdrawalPolicy,
    ) {
    }

    /**
     * @param  string  $payeeType  'provider' (payee_id = providers.id), 'field_worker' (payee_id =
     *                             field_workers.id — Admin Command Center mission: CommissionService has
     *                             credited a FieldWorker's wallet identically to a Provider's since Parcel/
     *                             Taxi/Marketplace shipped (applyForFieldWorkerOrder() treats both as the
     *                             same "individual earner" — see its own docblock), but this service had no
     *                             way to turn that balance into a payout request until now), or
     *                             'franchise_owner' (payee_id = users.id)
     */
    public function request(string $payeeType, int $payeeId, float $amount, ?int $paymentAccountId = null): Payout
    {
        if (! in_array($payeeType, ['provider', 'field_worker', 'franchise_owner'], true)) {
            throw new \InvalidArgumentException("Unknown payee_type [{$payeeType}].");
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payout amount must be positive.');
        }

        $this->assertWithinPayoutLimits($payeeType, $payeeId, $amount);
        $this->assertKycWithdrawalAllowed($payeeType, $payeeId);

        $user = $this->resolvePayeeUser($payeeType, $payeeId);
        $this->assertPaymentAccountBelongsToPayee($paymentAccountId, $user);

        return DB::transaction(function () use ($payeeType, $payeeId, $amount, $paymentAccountId, $user) {
            // Debit first (throws on insufficient balance) — a Payout row
            // only ever exists for money that's actually been set aside.
            $this->walletService->debit(
                $user,
                $amount,
                reason: 'Payout requested',
                ref: 'payout:'.Str::uuid()
            );

            return Payout::create([
                'payee_type' => $payeeType,
                'payee_id' => $payeeId,
                'payment_account_id' => $paymentAccountId,
                'amount' => $amount,
                'period_start' => now()->toDateString(),
                'period_end' => now()->toDateString(),
                'status' => 'pending',
            ]);
        });
    }

    public function markProcessing(Payout $payout): void
    {
        if ($payout->status !== 'pending') {
            throw new \RuntimeException("Payout #{$payout->id} is {$payout->status}, expected pending.");
        }

        $payout->update(['status' => 'processing']);
    }

    public function markPaid(Payout $payout, string $gatewayRef): void
    {
        if (! in_array($payout->status, ['pending', 'processing'], true)) {
            throw new \RuntimeException("Payout #{$payout->id} is {$payout->status}, expected pending/processing.");
        }

        $payout->update(['status' => 'paid', 'gateway_ref' => $gatewayRef, 'processed_at' => now()]);

        $user = $this->resolvePayeeUser($payout->payee_type, $payout->payee_id);
        $user->notify(new PayoutStatusNotification('paid', $payout->fresh(), ChannelResolver::resolve()));
    }

    /**
     * The money never actually left the platform, so it goes back into the
     * payee's wallet — same transaction-safe WalletService path as the
     * original debit, not a manual balance edit.
     */
    public function markFailed(Payout $payout, string $reason = ''): void
    {
        if (! in_array($payout->status, ['pending', 'processing'], true)) {
            throw new \RuntimeException("Payout #{$payout->id} is {$payout->status}, expected pending/processing.");
        }

        DB::transaction(function () use ($payout, $reason) {
            $user = $this->resolvePayeeUser($payout->payee_type, $payout->payee_id);

            $this->walletService->credit(
                $user,
                (float) $payout->amount,
                reason: 'Payout failed, refunded to wallet'.($reason ? ": {$reason}" : ''),
                ref: 'payout:'.$payout->id.':refund'
            );

            $payout->update(['status' => 'failed', 'processed_at' => now()]);

            $user->notify(new PayoutStatusNotification('failed', $payout->fresh(), ChannelResolver::resolve()));
        });
    }

    /**
     * wallet.provider_min/max_payout_amount or wallet.franchise_min/max_
     * payout_amount, resolved against the payee's own geography (a
     * provider's franchise/zone, or the franchise a franchise_owner
     * actually owns) — same Setting cascade every other scoped rule uses.
     * 0 for the max means "no cap" (the seeded default).
     */
    private function assertWithinPayoutLimits(string $payeeType, int $payeeId, float $amount): void
    {
        $scope = $this->payoutScope($payeeType, $payeeId);
        // field_worker reuses the SAME 'wallet.provider' limit namespace as
        // provider, not a new 'wallet.field_worker' one -- both are the
        // identical "individual earner" category CommissionService already
        // treats interchangeably (applyForFieldWorkerOrder()'s own
        // docblock); inventing a separate limit config for one of the two
        // types it already unifies would be a new, unevidenced policy
        // split, not implementing an existing one.
        $prefix = in_array($payeeType, ['provider', 'field_worker'], true) ? 'wallet.provider' : 'wallet.franchise';

        $min = (float) Setting::get("{$prefix}_min_payout_amount", '0', $scope);
        $max = (float) Setting::get("{$prefix}_max_payout_amount", '0', $scope);

        if ($amount < $min) {
            throw new \RuntimeException("Payout amount must be at least {$min}.");
        }

        if ($max > 0 && $amount > $max) {
            throw new \RuntimeException("Payout amount cannot exceed {$max}.");
        }
    }

    /**
     * The actual enforcement point for the KYC withdrawal restriction
     * policy (mission Phase 3) — earnings keep accruing to the wallet via
     * CommissionService regardless of KYC state (never touched), but a
     * request to turn that balance into a real payout is refused here once
     * the provider is genuinely overdue and holds no active exception.
     * franchise_owner payouts are NOT subject to this — the mission's own
     * 30-day/withdrawal-restriction text is Partner-specific throughout.
     *
     * field_worker ALSO falls through this early return today (unchanged
     * behavior) -- FieldWorker carries its own real kyc_status column, so
     * the same underlying risk this policy exists for plausibly applies,
     * but no source evidence ever named FieldWorker in the original
     * 30-day-restriction policy text the way it explicitly named Partner.
     * Extending the restriction here would be inventing a policy
     * extension, not implementing an evidenced one -- left as a real,
     * open, business-decision-blocked question, same discipline this
     * codebase applies everywhere else (see KNOWN_RISKS_AND_DECISIONS.md).
     */
    private function assertKycWithdrawalAllowed(string $payeeType, int $payeeId): void
    {
        if ($payeeType !== 'provider') {
            return;
        }

        $provider = Provider::findOrFail($payeeId);
        $explanation = $this->kycWithdrawalPolicy->explain($provider, $this->payoutScope($payeeType, $payeeId));

        if ($explanation['restricted']) {
            throw new \RuntimeException('Withdrawals are temporarily restricted until KYC is completed. Contact your Franchise Office for assistance.');
        }
    }

    /**
     * A supplied payment_account_id must actually belong to the resolved
     * payee -- prevents a payout ever being attributed to someone else's
     * bank/UPI account (an IDOR-adjacent integrity bug, not just a UX
     * nicety, since PaymentAccount previously had no write path at all
     * and this is the first real enforcement point for it).
     */
    private function assertPaymentAccountBelongsToPayee(?int $paymentAccountId, User $payeeUser): void
    {
        if (! $paymentAccountId) {
            return;
        }

        $belongs = PaymentAccount::where('id', $paymentAccountId)->where('user_id', $payeeUser->id)->exists();

        if (! $belongs) {
            throw new \RuntimeException('This payment account does not belong to the selected payee.');
        }
    }

    /**
     * Made public for Phase 21 item TECH-1 (row-level franchise scope for
     * Payouts\Manage/PayoutsExport) — this method already correctly resolves
     * a single payout's own franchise/zone/city/country hint from its
     * payee_type/payee_id discriminator (used internally for Setting
     * lookups); Payout::authorizationScopeHint() now reuses it as-is rather
     * than re-implementing the same provider→franchise / franchise_owner→
     * owned-franchise resolution a second time.
     */
    public function payoutScope(string $payeeType, int $payeeId): array
    {
        if ($payeeType === 'provider') {
            $provider = Provider::with('franchise')->find($payeeId);

            return $provider ? array_filter([
                'zone_id' => $provider->zone_id,
                'franchise_id' => $provider->franchise_id,
                'city_id' => $provider->franchise?->city_id,
                'country_id' => $provider->franchise?->country_id,
            ]) : [];
        }

        // Same shape as the provider branch above -- FieldWorker carries
        // the identical zone_id/franchise_id columns Provider does.
        if ($payeeType === 'field_worker') {
            $worker = FieldWorker::with('franchise')->find($payeeId);

            return $worker ? array_filter([
                'zone_id' => $worker->zone_id,
                'franchise_id' => $worker->franchise_id,
                'city_id' => $worker->franchise?->city_id,
                'country_id' => $worker->franchise?->country_id,
            ]) : [];
        }

        $franchise = Franchise::where('owner_user_id', $payeeId)->first();

        return $franchise ? array_filter([
            'franchise_id' => $franchise->id,
            'city_id' => $franchise->city_id,
            'country_id' => $franchise->country_id,
        ]) : [];
    }

    public function resolvePayeeUser(string $payeeType, int $payeeId): User
    {
        return match ($payeeType) {
            'provider' => Provider::findOrFail($payeeId)->user
                ?? throw new \RuntimeException("Provider #{$payeeId} has no linked user."),
            'field_worker' => FieldWorker::findOrFail($payeeId)->user
                ?? throw new \RuntimeException("FieldWorker #{$payeeId} has no linked user."),
            'franchise_owner' => User::findOrFail($payeeId),
            default => throw new \InvalidArgumentException("Unknown payee_type [{$payeeType}]."),
        };
    }
}
