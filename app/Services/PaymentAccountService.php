<?php

namespace App\Services;

use App\Models\PaymentAccount;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Payment Admin completion (mission Phase 9). `payment_accounts` existed
 * and was already READ by PayoutService::request()/Payouts\Manage (a
 * payee's settlement destination for a payout) but had ZERO write path
 * anywhere in the codebase -- confirmed by direct search before building
 * this. In a real deployment a provider/franchise owner had no way to
 * ever register a bank/UPI account, making PayoutService's own
 * $paymentAccountId parameter permanently unreachable in practice.
 *
 * `payment_methods` (the OTHER table this phase's risk-register item
 * named) stays deliberately untouched -- it genuinely duplicates the
 * `payment.*_enabled` Settings toggles and remains blocked on that
 * consolidation decision (KNOWN_RISKS_AND_DECISIONS.md item 11). This
 * service is scoped to payment_accounts only, which has no such conflict.
 *
 * Verification is ALWAYS an admin action, never self-attested — creating
 * or editing an account (self-service) always resets is_verified to
 * false; only PaymentAccountService::verify() (admin-only, called from
 * Payouts\Manage) can set it true.
 */
class PaymentAccountService
{
    public function create(User $user, array $data): PaymentAccount
    {
        $this->assertValid($data);

        return DB::transaction(function () use ($user, $data) {
            $isDefault = (bool) ($data['is_default'] ?? false) || ! PaymentAccount::where('user_id', $user->id)->exists();

            if ($isDefault) {
                PaymentAccount::where('user_id', $user->id)->update(['is_default' => false]);
            }

            return PaymentAccount::create([
                'user_id' => $user->id,
                'account_type' => $data['account_type'],
                'account_holder_name' => $data['account_holder_name'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'ifsc' => $data['ifsc'] ?? null,
                'upi_id' => $data['upi_id'] ?? null,
                'is_verified' => false,
                'is_default' => $isDefault,
            ]);
        });
    }

    /** Changing the actual settlement destination (account number/IFSC/UPI ID) always resets verification — a previously-verified account doesn't stay "verified" for different real-world details. */
    public function update(PaymentAccount $account, array $data): PaymentAccount
    {
        $merged = array_merge([
            'account_type' => $account->account_type,
            'account_holder_name' => $account->account_holder_name,
            'account_number' => $account->account_number,
            'ifsc' => $account->ifsc,
            'upi_id' => $account->upi_id,
        ], array_intersect_key($data, array_flip(['account_type', 'account_holder_name', 'account_number', 'ifsc', 'upi_id'])));

        $this->assertValid($merged);

        $detailsChanged = $merged['account_number'] !== $account->account_number
            || $merged['ifsc'] !== $account->ifsc
            || $merged['upi_id'] !== $account->upi_id;

        $account->fill($merged);
        if ($detailsChanged) {
            $account->is_verified = false;
        }
        $account->save();

        return $account->fresh();
    }

    public function setDefault(PaymentAccount $account): PaymentAccount
    {
        return DB::transaction(function () use ($account) {
            PaymentAccount::where('user_id', $account->user_id)->update(['is_default' => false]);
            $account->update(['is_default' => true]);

            return $account->fresh();
        });
    }

    /** Refuses to delete an account still referenced by an in-flight payout — the account isn't gone from the payout's own history (nullOnDelete on payouts.payment_account_id) but deleting it mid-transfer would be operationally confusing. */
    public function delete(PaymentAccount $account): void
    {
        $hasInFlightPayout = Payout::where('payment_account_id', $account->id)->whereIn('status', ['pending', 'processing'])->exists();

        if ($hasInFlightPayout) {
            throw new \RuntimeException('This account is referenced by an in-progress payout and cannot be deleted until it completes or fails.');
        }

        $account->delete();
    }

    public function verify(PaymentAccount $account): PaymentAccount
    {
        $account->update(['is_verified' => true]);

        return $account->fresh();
    }

    public function unverify(PaymentAccount $account): PaymentAccount
    {
        $account->update(['is_verified' => false]);

        return $account->fresh();
    }

    private function assertValid(array $data): void
    {
        if (! in_array($data['account_type'] ?? null, ['bank', 'upi'], true)) {
            throw new \InvalidArgumentException('account_type must be "bank" or "upi".');
        }

        if ($data['account_type'] === 'bank') {
            if (empty($data['account_number']) || empty($data['ifsc']) || empty($data['account_holder_name'])) {
                throw new \InvalidArgumentException('Bank accounts require account_holder_name, account_number, and ifsc.');
            }
        } else {
            if (empty($data['upi_id'])) {
                throw new \InvalidArgumentException('UPI accounts require a upi_id.');
            }
        }
    }
}
