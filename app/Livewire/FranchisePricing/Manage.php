<?php

namespace App\Livewire\FranchisePricing;

use App\Models\Franchise;
use App\Models\FranchiseServicePricing;
use App\Models\Service;
use App\Models\Setting;
use Livewire\Component;

/**
 * franchise_service_pricing has existed since P1 with a real consumer
 * (Bookings\Index's price-override lookup) but never had an admin write
 * path. One franchise per screen (reached from a "Pricing" link on the
 * Franchises list), one row per active service — the same "no row yet"
 * default Bookings\Index already assumes: base price applies, service is
 * offered. Saving a row only ever touches that one service's override,
 * never the whole matrix at once.
 */
class Manage extends Component
{
    public Franchise $franchise;

    public string $search = '';

    /** service_id => ['is_offered' => bool, 'price_override' => string] — edit buffer, not the source of truth. */
    public array $rows = [];

    public string $flashMessage = '';
    public string $flashType = 'success';

    public function mount(int $franchiseId): void
    {
        $this->franchise = Franchise::findOrFail($franchiseId);
        $this->loadRows();
    }

    private function loadRows(): void
    {
        $overrides = FranchiseServicePricing::where('franchise_id', $this->franchise->id)
            ->get()
            ->keyBy('service_id');

        $this->rows = Service::where('is_active', true)
            ->get()
            ->mapWithKeys(function (Service $service) use ($overrides) {
                $existing = $overrides->get($service->id);
                return [$service->id => [
                    'is_offered' => $existing ? (bool) $existing->is_offered : true,
                    'price_override' => $existing?->price_override !== null ? (string) $existing->price_override : '',
                ]];
            })->all();
    }

    private function canManage(): bool
    {
        if (! auth()->user()->hasPermission('franchise_pricing.manage', [
            'franchise_id' => $this->franchise->id,
        ])) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage pricing for this franchise.';
            return false;
        }

        return true;
    }

    public function saveRow(int $serviceId): void
    {
        if (! $this->canManage()) {
            return;
        }

        $service = Service::findOrFail($serviceId);
        $row = $this->rows[$serviceId] ?? ['is_offered' => true, 'price_override' => ''];

        $this->validate([
            "rows.{$serviceId}.price_override" => ['nullable', 'numeric', 'min:0'],
        ], [], ["rows.{$serviceId}.price_override" => 'price override']);

        FranchiseServicePricing::updateOrCreate(
            ['franchise_id' => $this->franchise->id, 'service_id' => $service->id],
            [
                'is_offered' => (bool) $row['is_offered'],
                'price_override' => $row['price_override'] === '' ? null : $row['price_override'],
            ]
        );

        $this->flashType = 'success';
        $this->flashMessage = "{$service->name} pricing saved.";
    }

    /** Deletes the override row entirely — back to "base price, offered" default. */
    public function resetRow(int $serviceId): void
    {
        if (! $this->canManage()) {
            return;
        }

        $service = Service::findOrFail($serviceId);

        FranchiseServicePricing::where('franchise_id', $this->franchise->id)
            ->where('service_id', $service->id)
            ->delete();

        $this->rows[$serviceId] = ['is_offered' => true, 'price_override' => ''];

        $this->flashType = 'success';
        $this->flashMessage = "{$service->name} reset to base price.";
    }

    public function render()
    {
        $services = Service::with('category')
            ->where('is_active', true)
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->orderBy('name')
            ->get();

        return view('livewire.franchise-pricing.manage', [
            'services' => $services,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('layouts.admin', ['title' => 'Service Pricing — ' . $this->franchise->name]);
    }
}
