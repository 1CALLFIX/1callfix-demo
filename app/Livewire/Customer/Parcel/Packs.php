<?php

namespace App\Livewire\Customer\Parcel;

use App\Models\ParcelPack;
use App\Models\ParcelPackPurchase;
use App\Models\Setting;
use App\Services\Customer\CustomerLocationContext;
use App\Services\ModuleActivationService;
use App\Services\Parcel\ParcelPackService;
use App\Support\Modules;
use Livewire\Component;

/**
 * Customer "Parcel packs" (Stage 2, owner decision 2026-10-31): prepaid parcels for shops and offices. Lists the packs
 * on sale where the customer is browsing and the packs they own (credits left, expiry). Bought online (Razorpay — the
 * capture webhook activates it) or from the wallet; never cash.
 */
class Packs extends Component
{
    public string $notice = '';

    public string $error = '';

    public function mount(): void
    {
        abort_unless(app(ModuleActivationService::class)->isActiveAnywhere(Modules::PARCEL), 404);
    }

    public function buy(int $packId, string $method, ParcelPackService $packs): void
    {
        $this->reset('notice', 'error');
        $pack = $packs->onSale($this->franchiseId())->firstWhere('id', $packId);
        if (! $pack) {
            $this->error = 'This pack is not on sale here.';

            return;
        }

        try {
            $result = $packs->buy(auth()->user(), $pack, $method);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        if ($result['requires_payment']) {
            $this->dispatch('razorpay-open', order: $result['checkout'])->self();

            return;
        }
        $this->notice = "Done. {$pack->parcel_count} parcels are ready to use.";
    }

    private function franchiseId(): ?int
    {
        return app(CustomerLocationContext::class)->zone()?->franchise_id;
    }

    public function render(ParcelPackService $packs)
    {
        return view('livewire.customer.parcel.packs', [
            'onSale' => $packs->onSale($this->franchiseId()),
            'mine' => ParcelPackPurchase::where('user_id', auth()->id())->where('status', 'active')->latest('id')->get(),
            'walletOn' => Setting::get('payment.wallet_enabled') === '1',
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => 'Parcel packs']);
    }
}
