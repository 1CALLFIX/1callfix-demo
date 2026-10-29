<?php

namespace App\Livewire\Seller;

use App\Livewire\Seller\Concerns\InteractsWithSeller;
use App\Models\MarketplaceSettlement;
use App\Models\Setting;
use App\Services\Marketplace\MarketplaceSettlementService;
use App\Services\WalletService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Seller earnings: the wallet balance (released shop earnings land there), what is still held for the return window
 * (with the date each order is released), and commission owed back on cash-at-pickup orders. A payout is requested
 * on Seller → Request Payout (the partner screen, reused); admin pays it from Finance → Payouts.
 */
class Earnings extends Component
{
    use InteractsWithSeller;
    use WithPagination;

    public string $notice = '';

    public function settle(MarketplaceSettlementService $settlements): void
    {
        $recovered = $settlements->settleCash('provider', $this->seller()->id);
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $this->notice = $recovered > 0
            ? 'Settled '.$symbol.number_format($recovered, 2).' from your wallet.'
            : 'Nothing was settled — your wallet balance is empty.';
    }

    public function render()
    {
        $seller = $this->seller();
        $rows = MarketplaceSettlement::where('provider_id', $seller->id);

        return view('livewire.seller.earnings', [
            'balance' => $seller->user ? app(WalletService::class)->balance($seller->user) : 0.0,
            'held' => (float) (clone $rows)->where('status', 'held')->sum('seller_share'),
            'owed' => app(MarketplaceSettlementService::class)->outstanding('provider', $seller->id),
            'settlements' => (clone $rows)->with('marketplaceOrder:id,code,payment_method,completed_at')->latest('id')->paginate(20),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.seller', ['title' => 'Earnings']);
    }
}
