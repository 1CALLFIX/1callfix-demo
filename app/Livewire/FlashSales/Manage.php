<?php

namespace App\Livewire\FlashSales;

use App\Models\Badge;
use App\Models\FlashSale;
use App\Models\FlashSaleTarget;
use App\Models\Service;
use App\Models\Setting;
use App\Services\AuthorizationService;
use App\Services\FlashSaleService;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Flash Sale Engine admin screen — create (draft), schedule/lifecycle
 * actions, target assignment, and a read-only redemptions view. Row-level
 * scoped from the start (learned from the Badge Engine slice, applied here
 * immediately rather than deferred).
 */
class Manage extends Component
{
    use WithPagination;

    public string $section = 'sales'; // sales|redemptions

    // --- Create form ---
    public string $name = '';
    public string $customerTitle = '';
    public string $description = '';
    public string $type = 'urgent_sale';
    public string $discountType = 'percent';
    public string $discountValue = '10';
    public string $maxDiscount = '';
    public string $minFinalPrice = '0';
    public string $totalQuantityLimit = '';
    public string $perCustomerLimit = '';
    public string $scopeType = 'global';
    public ?int $scopeCountryId = null;
    public ?int $scopeCityId = null;
    public ?int $scopeFranchiseId = null;
    public ?int $scopeZoneId = null;
    public ?int $badgeId = null;

    // --- Schedule modal ---
    public ?int $schedulingSaleId = null;
    public string $scheduleStartsAt = '';
    public string $scheduleEndsAt = '';

    // --- Target assignment ---
    public ?int $expandedSaleId = null;
    public string $targetServiceSearch = '';
    public ?int $targetServiceId = null;

    public string $flashMessage = '';
    public string $flashType = 'success';

    /** flash_sales.view was seeded (2026_08_14_010000) specifically for this screen. */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('flash_sales.view'), 403, 'You do not have permission to view flash sales.');
    }

    public function setSection(string $section): void
    {
        $this->section = in_array($section, ['sales', 'redemptions'], true) ? $section : 'sales';
    }

    public function updatedScopeType(): void { $this->reset(['scopeCountryId', 'scopeCityId', 'scopeFranchiseId', 'scopeZoneId']); }
    public function updatedScopeFranchiseId(): void { $this->scopeZoneId = null; }

    private function resolvedScopeId(): ?int
    {
        return match ($this->scopeType) {
            'country' => $this->scopeCountryId,
            'city' => $this->scopeCityId,
            'franchise' => $this->scopeFranchiseId,
            'zone' => $this->scopeZoneId,
            default => null,
        };
    }

    private function canManageScope(array $scope): bool
    {
        return auth()->user()->hasPermission('flash_sales.manage', $scope);
    }

    public function create(): void
    {
        $targetScope = app(AuthorizationService::class)->ancestryFor($this->scopeType, $this->resolvedScopeId());
        if (! $this->canManageScope($targetScope)) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to create a flash sale at this scope.';
            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'customerTitle' => ['required', 'string', 'max:150'],
            'discountType' => ['required', 'in:flat,percent'],
            'discountValue' => ['required', 'numeric', 'min:0.01'],
            'scopeType' => ['required', 'in:global,country,city,zone,franchise'],
        ]);

        if ($this->discountType === 'percent' && (float) $this->discountValue > 100) {
            $this->addError('discountValue', 'A percent discount cannot exceed 100.');
            return;
        }

        FlashSale::create([
            'name' => $this->name, 'customer_title' => $this->customerTitle, 'description' => $this->description ?: null,
            'type' => $this->type, 'status' => 'draft',
            'scope_type' => $this->scopeType, 'scope_id' => $this->scopeType === 'global' ? null : $this->resolvedScopeId(),
            'discount_type' => $this->discountType, 'discount_value' => $this->discountValue,
            'max_discount' => $this->maxDiscount !== '' ? $this->maxDiscount : null,
            'min_final_price' => $this->minFinalPrice ?: 0,
            'total_quantity_limit' => $this->totalQuantityLimit !== '' ? (int) $this->totalQuantityLimit : null,
            'per_customer_limit' => $this->perCustomerLimit !== '' ? (int) $this->perCustomerLimit : null,
            'badge_id' => $this->badgeId ?: null,
            'created_by' => auth()->id(),
        ]);

        $this->reset(['name', 'customerTitle', 'description', 'discountValue', 'maxDiscount', 'minFinalPrice', 'totalQuantityLimit', 'perCustomerLimit', 'badgeId', 'scopeCountryId', 'scopeCityId', 'scopeFranchiseId', 'scopeZoneId']);
        $this->discountValue = '10';
        $this->minFinalPrice = '0';
        $this->flashType = 'success';
        $this->flashMessage = 'Flash sale created as a draft.';
    }

    public function startScheduling(int $saleId): void
    {
        $this->schedulingSaleId = $saleId;
        $this->scheduleStartsAt = '';
        $this->scheduleEndsAt = '';
    }

    public function schedule(FlashSaleService $service): void
    {
        $sale = FlashSale::findOrFail($this->schedulingSaleId);
        if (! $this->canManageScope($sale->authorizationScopeHint())) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to schedule this sale.';
            return;
        }

        $this->validate(['scheduleStartsAt' => ['required', 'date'], 'scheduleEndsAt' => ['required', 'date', 'after:scheduleStartsAt']]);

        try {
            $service->schedule($sale, Carbon::parse($this->scheduleStartsAt), Carbon::parse($this->scheduleEndsAt));
            $this->flashType = 'success';
            $this->flashMessage = 'Flash sale scheduled.';
            $this->schedulingSaleId = null;
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function lifecycleAction(int $saleId, string $action, FlashSaleService $service): void
    {
        $sale = FlashSale::findOrFail($saleId);
        if (! $this->canManageScope($sale->authorizationScopeHint())) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage this sale.';
            return;
        }

        if (! in_array($action, ['goLive', 'pause', 'resume', 'cancel', 'complete'], true)) {
            return;
        }

        try {
            $service->{$action}($sale);
            $this->flashType = 'success';
            $this->flashMessage = 'Flash sale updated.';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function expandTargets(int $saleId): void
    {
        $this->expandedSaleId = $this->expandedSaleId === $saleId ? null : $saleId;
        $this->reset(['targetServiceSearch', 'targetServiceId']);
    }

    public function getMatchingTargetServicesProperty()
    {
        if ($this->targetServiceId || mb_strlen(trim($this->targetServiceSearch)) < 2) {
            return collect();
        }

        return Service::where('name', 'like', '%'.$this->targetServiceSearch.'%')->orderBy('name')->limit(8)->get();
    }

    public function selectTargetService(int $serviceId): void
    {
        $this->targetServiceId = $serviceId;
        $this->targetServiceSearch = Service::find($serviceId)?->name ?? '';
    }

    public function addTarget(FlashSaleService $service): void
    {
        $sale = FlashSale::findOrFail($this->expandedSaleId);
        if (! $this->canManageScope($sale->authorizationScopeHint())) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage this sale.';
            return;
        }

        if (! $this->targetServiceId) {
            $this->addError('targetServiceId', 'Pick a service first.');
            return;
        }

        try {
            $service->addTarget($sale, Service::findOrFail($this->targetServiceId));
            $this->flashType = 'success';
            $this->flashMessage = 'Service added to this sale.';
            $this->reset(['targetServiceSearch', 'targetServiceId']);
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function removeTarget(int $targetId, FlashSaleService $service): void
    {
        $target = FlashSaleTarget::findOrFail($targetId);
        if (! $this->canManageScope($target->flashSale->authorizationScopeHint())) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage this sale.';
            return;
        }

        $service->removeTarget($target);
        $this->flashType = 'success';
        $this->flashMessage = 'Service removed from this sale.';
    }

    public function render()
    {
        $flashSaleService = app(FlashSaleService::class);
        $authz = app(AuthorizationService::class);

        $sales = $authz->visibleAmong(
            FlashSale::with(['targets.service', 'badge'])->latest()->get(),
            auth()->user(),
            'flash_sales.view',
        )->map(function (FlashSale $sale) use ($flashSaleService) {
            $sale->setAttribute('is_currently_active', $sale->isCurrentlyActive());
            $sale->setAttribute('remaining_quantity', $flashSaleService->remainingQuantity($sale));

            return $sale;
        });

        $redemptions = \App\Models\FlashSaleRedemption::with(['flashSale', 'service', 'user'])->latest()->limit(100)->get();

        return view('livewire.flash-sales.manage', [
            'sales' => $sales,
            'redemptions' => $redemptions,
            'countries' => \App\Models\Country::where('is_active', true)->orderBy('name')->get(),
            'cities' => $this->scopeCountryId ? \App\Models\City::where('country_id', $this->scopeCountryId)->orderBy('name')->get() : collect(),
            'franchises' => \App\Models\Franchise::orderBy('name')->get(),
            'zones' => $this->scopeFranchiseId ? \App\Models\Zone::where('franchise_id', $this->scopeFranchiseId)->orderBy('name')->get() : collect(),
            'badges' => Badge::where('mode', 'manual')->orderBy('label')->get(),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('layouts.admin', ['title' => 'Flash Sales']);
    }
}
