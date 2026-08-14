<?php

namespace App\Livewire\Payouts;

use App\Models\PaymentAccount;
use App\Models\Payout;
use App\Models\Provider;
use App\Models\User;
use App\Services\PayoutService;
use App\Services\WalletService;
use Livewire\Component;
use Livewire\WithPagination;

// Disbursement — the missing half of Commission: CommissionService already
// credits provider/franchise-owner wallets in real time at booking
// completion (see App\Services\CommissionService); this screen is where
// that wallet balance turns into an actual payout request, tracked through
// to paid/failed via PayoutService. See PayoutService's docblock for why
// there's no live gateway call here yet.
class Manage extends Component
{
    use WithPagination;

    public string $payeeType = 'provider'; // provider|franchise_owner
    public string $payeeSearch = '';
    public ?int $selectedPayeeId = null;
    public string $selectedPayeeLabel = '';
    public string $amount = '';
    public ?int $paymentAccountId = null;

    public string $flashMessage = '';
    public string $flashType = 'success';

    // --- inline "mark paid" gateway-ref capture ---
    public ?int $markingPaidId = null;
    public string $gatewayRefInput = '';

    public function updatedPayeeType(): void
    {
        $this->reset(['payeeSearch', 'selectedPayeeId', 'selectedPayeeLabel', 'paymentAccountId']);
    }

    public function getMatchingPayeesProperty()
    {
        if (mb_strlen($this->payeeSearch) < 2) {
            return collect();
        }

        if ($this->payeeType === 'provider') {
            return Provider::with('user')
                ->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$this->payeeSearch}%")->orWhere('phone', 'like', "%{$this->payeeSearch}%"))
                ->limit(8)->get()
                ->map(fn ($p) => ['id' => $p->id, 'label' => ($p->user->name ?? 'Provider #'.$p->id).' — '.($p->user->phone ?? '—')]);
        }

        return User::where('name', 'like', "%{$this->payeeSearch}%")
            ->orWhere('phone', 'like', "%{$this->payeeSearch}%")
            ->limit(8)->get()
            ->map(fn ($u) => ['id' => $u->id, 'label' => $u->name.' — '.$u->phone]);
    }

    public function selectPayee(int $id, string $label): void
    {
        $this->selectedPayeeId = $id;
        $this->selectedPayeeLabel = $label;
        $this->paymentAccountId = null;
    }

    public function getPayeeWalletBalanceProperty(): ?float
    {
        if (! $this->selectedPayeeId) {
            return null;
        }

        $user = app(PayoutService::class)->resolvePayeeUser($this->payeeType, $this->selectedPayeeId);

        return app(WalletService::class)->balance($user);
    }

    public function getPayeePaymentAccountsProperty()
    {
        if (! $this->selectedPayeeId) {
            return collect();
        }

        $user = app(PayoutService::class)->resolvePayeeUser($this->payeeType, $this->selectedPayeeId);

        return PaymentAccount::where('user_id', $user->id)->get();
    }

    public function request(PayoutService $service): void
    {
        if (! auth()->user()->hasPermission('payouts.manage')) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to request payouts.';
            return;
        }

        $this->validate([
            'selectedPayeeId' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ], [], ['selectedPayeeId' => 'payee', 'amount' => 'amount']);

        try {
            $service->request($this->payeeType, $this->selectedPayeeId, (float) $this->amount, $this->paymentAccountId);
            $this->reset(['payeeSearch', 'selectedPayeeId', 'selectedPayeeLabel', 'amount', 'paymentAccountId']);
            $this->flashType = 'success';
            $this->flashMessage = 'Payout requested — amount held from wallet balance.';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function markProcessing(int $payoutId, PayoutService $service): void
    {
        if (! auth()->user()->hasPermission('payouts.manage')) {
            return;
        }

        try {
            $service->markProcessing(Payout::findOrFail($payoutId));
            $this->flashType = 'success';
            $this->flashMessage = 'Payout marked processing.';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function startMarkPaid(int $payoutId): void
    {
        $this->markingPaidId = $payoutId;
        $this->gatewayRefInput = '';
    }

    public function confirmMarkPaid(PayoutService $service): void
    {
        if (! auth()->user()->hasPermission('payouts.manage')) {
            return;
        }

        $this->validate(['gatewayRefInput' => ['required', 'string', 'max:255']], [], ['gatewayRefInput' => 'transfer reference']);

        try {
            $service->markPaid(Payout::findOrFail($this->markingPaidId), $this->gatewayRefInput);
            $this->markingPaidId = null;
            $this->flashType = 'success';
            $this->flashMessage = 'Payout marked paid.';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function markFailed(int $payoutId, PayoutService $service): void
    {
        if (! auth()->user()->hasPermission('payouts.manage')) {
            return;
        }

        try {
            $service->markFailed(Payout::findOrFail($payoutId), 'Marked failed by admin');
            $this->flashType = 'success';
            $this->flashMessage = 'Payout marked failed — amount refunded to wallet.';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    /**
     * Verification is always an admin action (payment_accounts had zero
     * write path before this session -- see PaymentAccountService's own
     * docblock). Reuses payouts.manage rather than a new permission --
     * this is part of the same "get a payee ready to actually be paid"
     * capability the rest of this screen already gates that way.
     */
    public function verifyPaymentAccount(int $accountId, \App\Services\PaymentAccountService $service): void
    {
        if (! auth()->user()->hasPermission('payouts.manage')) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to verify payment accounts.';
            return;
        }

        $service->verify(\App\Models\PaymentAccount::findOrFail($accountId));
        $this->flashType = 'success';
        $this->flashMessage = 'Payment account verified.';
    }

    public function unverifyPaymentAccount(int $accountId, \App\Services\PaymentAccountService $service): void
    {
        if (! auth()->user()->hasPermission('payouts.manage')) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to unverify payment accounts.';
            return;
        }

        $service->unverify(\App\Models\PaymentAccount::findOrFail($accountId));
        $this->flashType = 'success';
        $this->flashMessage = 'Payment account verification revoked.';
    }

    private function payeeLabel(Payout $payout): string
    {
        if ($payout->payee_type === 'provider') {
            $p = Provider::with('user')->find($payout->payee_id);
            return $p ? ($p->user->name ?? 'Provider #'.$p->id).' (provider)' : 'Provider #'.$payout->payee_id.' (provider)';
        }

        $u = User::find($payout->payee_id);
        return $u ? $u->name.' (franchise owner)' : 'User #'.$payout->payee_id.' (franchise owner)';
    }

    public function render()
    {
        $payouts = Payout::latest()->paginate(15);
        $payouts->getCollection()->transform(function ($p) {
            $p->display_label = $this->payeeLabel($p);
            return $p;
        });

        return view('livewire.payouts.manage', ['payouts' => $payouts])
            ->layout('layouts.admin', ['title' => 'Payouts']);
    }
}
