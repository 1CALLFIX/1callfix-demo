<?php

namespace App\Livewire\Provider;

use App\Models\PaymentAccount;
use App\Services\PaymentAccountService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Provider self-service settlement accounts. Closes the gap found while
 * building the admin Payouts screen: `payment_accounts` was already READ by
 * PayoutService/Payouts\Manage but had zero provider-facing write path — a
 * provider had no way to ever register where a payout should land. Wired
 * straight onto the existing, previously-unused PaymentAccountService;
 * no new service logic here.
 *
 * `payment_accounts.user_id` is a plain FK to `users`, not to `providers`
 * (see the model/migration) — a provider's accounts ARE their user's
 * accounts, same as PaymentAccountController (API self-service) already
 * assumes. So this reads/writes off auth()->id()/auth()->user() directly
 * rather than resolving a Provider model — there is nothing provider-shaped
 * to resolve for this particular screen.
 *
 * Every lookup below is scoped to the caller's own user_id and uses
 * findOrFail() against that scoped query — never a bare
 * PaymentAccount::findOrFail($id) — so another provider's account id
 * 404s instead of ever being loaded, let alone mutated.
 *
 * New/edited accounts are never auto-verified — that stays an admin-only
 * action via Payouts\Manage's verify()/unverify() (unchanged). This screen
 * only ever writes is_verified=false, via the service's own guarantee.
 */
class PaymentAccounts extends Component
{
    public string $accountType = 'upi';

    public string $accountHolderName = '';

    public string $accountNumber = '';

    public string $ifsc = '';

    public string $upiId = '';

    public bool $isDefault = false;

    public ?int $editingId = null;

    public bool $showForm = false;

    public string $flashType = 'success';

    public string $flashMessage = '';

    private function ownAccounts()
    {
        return PaymentAccount::where('user_id', Auth::id());
    }

    public function startCreate(): void
    {
        $this->reset(['accountType', 'accountHolderName', 'accountNumber', 'ifsc', 'upiId', 'isDefault', 'editingId']);
        $this->accountType = 'upi';
        $this->showForm = true;
    }

    public function startEdit(int $id): void
    {
        $account = $this->ownAccounts()->findOrFail($id);

        $this->editingId = $account->id;
        $this->accountType = $account->account_type;
        $this->accountHolderName = (string) $account->account_holder_name;
        $this->accountNumber = (string) $account->account_number;
        $this->ifsc = (string) $account->ifsc;
        $this->upiId = (string) $account->upi_id;
        $this->isDefault = $account->is_default;
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->reset(['accountType', 'accountHolderName', 'accountNumber', 'ifsc', 'upiId', 'isDefault', 'editingId']);
        $this->accountType = 'upi';
        $this->showForm = false;
    }

    public function save(PaymentAccountService $service): void
    {
        $this->validate([
            'accountType' => ['required', 'in:bank,upi'],
            'accountHolderName' => ['nullable', 'string', 'max:150'],
            'accountNumber' => ['nullable', 'string', 'max:50'],
            'ifsc' => ['nullable', 'string', 'max:20'],
            'upiId' => ['nullable', 'string', 'max:100'],
        ], [], [
            'accountType' => 'account type',
            'accountHolderName' => 'account holder name',
            'accountNumber' => 'account number',
            'upiId' => 'UPI ID',
        ]);

        $data = [
            'account_type' => $this->accountType,
            'account_holder_name' => $this->accountHolderName ?: null,
            'account_number' => $this->accountNumber ?: null,
            'ifsc' => $this->ifsc ?: null,
            'upi_id' => $this->upiId ?: null,
            'is_default' => $this->isDefault,
        ];

        try {
            if ($this->editingId) {
                $account = $this->ownAccounts()->findOrFail($this->editingId);
                $service->update($account, $data);
                if ($this->isDefault && ! $account->is_default) {
                    $service->setDefault($account->fresh());
                }
                $this->flashMessage = 'Payment account updated.';
            } else {
                $service->create(Auth::user(), $data);
                $this->flashMessage = 'Payment account added — pending admin verification.';
            }

            $this->flashType = 'success';
            $this->cancelForm();
        } catch (\InvalidArgumentException $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function setDefault(int $id, PaymentAccountService $service): void
    {
        $account = $this->ownAccounts()->findOrFail($id);
        $service->setDefault($account);

        $this->flashType = 'success';
        $this->flashMessage = 'Default payment account updated.';
    }

    public function delete(int $id, PaymentAccountService $service): void
    {
        $account = $this->ownAccounts()->findOrFail($id);

        try {
            $service->delete($account);
            $this->flashType = 'success';
            $this->flashMessage = 'Payment account removed.';
        } catch (\RuntimeException $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.provider.payment-accounts', [
            'accounts' => $this->ownAccounts()->orderByDesc('is_default')->orderByDesc('id')->get(),
        ])->layout('components.layouts.provider', ['title' => 'Payment Accounts']);
    }
}
