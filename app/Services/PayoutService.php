<?php

namespace App\Services;

use App\Models\Franchise;
use App\Models\Payout;
use App\Models\Provider;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PayoutStatusNotification;
use App\Notifications\Support\ChannelResolver;
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
    public function __construct(private WalletService $walletService)
    {
    }

    /**
     * @param  string  $payeeType  'provider' (payee_id = providers.id) or 'franchise_owner' (payee_id = users.id)
     */
    public function request(string $payeeType, int $payeeId, float $amount, ?int $paymentAccountId = null): Payout
    {
        if (! in_array($payeeType, ['provider', 'franchise_owner'], true)) {
            throw new \InvalidArgumentException("Unknown payee_type [{$payeeType}].");
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payout amount must be positive.');
        }

        $this->assertWithinPayoutLimits($payeeType, $payeeId, $amount);

        $user = $this->resolvePayeeUser($payeeType, $payeeId);

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
        $prefix = $payeeType === 'provider' ? 'wallet.provider' : 'wallet.franchise';

        $min = (float) Setting::get("{$prefix}_min_payout_amount", '0', $scope);
        $max = (float) Setting::get("{$prefix}_max_payout_amount", '0', $scope);

        if ($amount < $min) {
            throw new \RuntimeException("Payout amount must be at least {$min}.");
        }

        if ($max > 0 && $amount > $max) {
            throw new \RuntimeException("Payout amount cannot exceed {$max}.");
        }
    }

    private function payoutScope(string $payeeType, int $payeeId): array
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
            'franchise_owner' => User::findOrFail($payeeId),
            default => throw new \InvalidArgumentException("Unknown payee_type [{$payeeType}]."),
        };
    }
}
