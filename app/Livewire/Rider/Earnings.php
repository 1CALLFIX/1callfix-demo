<?php

namespace App\Livewire\Rider;

use App\Livewire\Rider\Concerns\InteractsWithRider;
use App\Models\FieldWorkerCashDue;
use App\Models\Setting;
use App\Services\Parcel\FieldWorkerCashDueService;
use App\Services\WalletService;
use Livewire\Component;

/**
 * Rider earnings: wallet balance (online/wallet parcels credit it on delivery), cash dues (what they owe after
 * collecting cash), and "Settle from wallet". Payouts themselves are made by admin (Finance → Payouts,
 * payee type field worker), which refuses while dues remain.
 */
class Earnings extends Component
{
    use InteractsWithRider;

    public string $notice = '';

    public function settle(FieldWorkerCashDueService $dues): void
    {
        $recovered = $dues->settle($this->rider());
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $this->notice = $recovered > 0
            ? 'Settled '.$symbol.number_format($recovered, 2).' from your wallet.'
            : 'Nothing was settled — your wallet balance is empty.';
    }

    public function render()
    {
        $rider = $this->rider();
        $dues = app(FieldWorkerCashDueService::class);

        return view('livewire.rider.earnings', [
            'balance' => $rider->user ? app(WalletService::class)->balance($rider->user) : 0.0,
            'owed' => $dues->outstanding($rider),
            'openDues' => FieldWorkerCashDue::outstanding()->where('field_worker_id', $rider->id)->with('parcelOrder:id,code')->orderBy('id')->get(),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.rider', ['title' => 'Earnings']);
    }
}
