<?php

namespace App\Livewire\Rider;

use App\Livewire\Rider\Concerns\InteractsWithRider;
use App\Models\PaymentAccount;
use App\Models\Payout;
use App\Models\Setting;
use App\Services\Parcel\FieldWorkerCashDueService;
use App\Services\PayoutService;
use App\Services\WalletService;
use Livewire\Component;

/**
 * Rider payout request, on the partner Request Payout screen (same view, same rules): the rider picks one of their
 * own VERIFIED accounts and PayoutService::request('field_worker', ...) does the rest — cash dues are recovered from
 * the wallet first and the request is refused while any remain, then the provider payout limits and the wallet
 * debit-first hold apply. Admin pays it from Finance → Payouts as before.
 */
class RequestPayout extends Component
{
    use InteractsWithRider;

    public string $amount = '';

    public ?int $paymentAccountId = null;

    public string $flashType = 'success';

    public string $flashMessage = '';

    private function verifiedAccounts()
    {
        return PaymentAccount::where('user_id', auth()->id())->where('is_verified', true)->orderByDesc('is_default');
    }

    public function mount(): void
    {
        $this->paymentAccountId = $this->verifiedAccounts()->value('id');
    }

    public function request(PayoutService $service): void
    {
        $rider = $this->rider();

        $this->validate([
            'paymentAccountId' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ], [], ['paymentAccountId' => 'payment account', 'amount' => 'amount']);

        if (! $this->verifiedAccounts()->whereKey($this->paymentAccountId)->exists()) {
            $this->flashType = 'error';
            $this->flashMessage = 'Select one of your verified payment accounts.';

            return;
        }

        try {
            $service->request('field_worker', $rider->id, (float) $this->amount, $this->paymentAccountId);

            $this->reset(['amount']);
            $this->flashType = 'success';
            $this->flashMessage = 'Payout requested — the amount is now held from your wallet balance.';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function render(WalletService $wallet, PayoutService $payoutService, FieldWorkerCashDueService $dues)
    {
        $rider = $this->rider();
        $scope = $payoutService->payoutScope('field_worker', $rider->id);
        $balance = $rider->user ? $wallet->balance($rider->user) : 0.0;
        $owed = $dues->outstanding($rider);

        return view('livewire.provider.request-payout', [
            'area' => 'rider',
            'walletBalance' => $balance,
            'outstandingCashCommission' => $owed,
            'outstandingDisputeDebt' => 0,
            'withdrawableBalance' => max(0, round($balance - $owed, 2)),
            'verifiedAccounts' => $this->verifiedAccounts()->get(),
            // PayoutService uses the provider limits for field workers too.
            'minPayout' => (float) Setting::get('wallet.provider_min_payout_amount', '0', $scope),
            'maxPayout' => (float) Setting::get('wallet.provider_max_payout_amount', '0', $scope),
            // PayoutService applies no KYC withdrawal restriction to field workers; an unapproved rider gets no offers.
            'kyc' => ['restricted' => false],
            'payouts' => Payout::where('payee_type', 'field_worker')->where('payee_id', $rider->id)
                ->with('paymentAccount')->latest('id')->limit(20)->get(),
        ])->layout('components.layouts.rider', ['title' => 'Request Payout']);
    }
}
