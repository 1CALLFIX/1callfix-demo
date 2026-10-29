<?php

namespace App\Livewire\MarketplaceCommission;

use App\Models\City;
use App\Models\Franchise;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceCommissionRule;
use App\Models\Store;
use App\Services\ActivityLogger;
use App\Services\AuthorizationService;
use App\Services\Marketplace\MarketplaceCommissionResolver;
use App\Support\MarketplaceSettings;
use App\Support\Modules;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Admin → Shop Commission (E-commerce Phase 4): the platform fee % on shop goods by shop, category, franchise and
 * city; the most specific rule wins (MarketplaceCommissionResolver). Permission marketplace_commission.manage:
 * Super Admin and HQ (global / country / city grants) set any rule; a franchise-scoped holder only rules for their
 * own franchise, between the Shop Settings bounds (blank bounds = they cannot set rules). Audit-logged.
 */
class Manage extends Component
{
    private const PERMISSION = 'marketplace_commission.manage';

    public ?int $storeId = null;
    public ?int $categoryId = null;
    public ?int $franchiseId = null;
    public ?int $cityId = null;
    public string $percent = '';

    public ?int $previewStoreId = null;
    public ?int $previewCategoryId = null;

    public string $flashMessage = '';
    public string $flashType = 'success';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere(self::PERMISSION), 403);
        if (! $this->isHq()) {
            $this->franchiseId = $this->ownFranchiseIds()[0] ?? null;
        }
    }

    private function isHq(): bool
    {
        $user = auth()->user();
        if ($user->role === 'super_admin') {
            return true;
        }

        return $user->roleAssignments()->with('role.permissions')->get()
            ->contains(fn ($a) => $a->scope_type !== 'franchise' && $a->role->permissions->contains('slug', self::PERMISSION));
    }

    /** Franchises a franchise-scoped holder may set rules for. */
    private function ownFranchiseIds(): array
    {
        return auth()->user()->roleAssignments()->with('role.permissions')->get()
            ->filter(fn ($a) => $a->scope_type === 'franchise' && $a->role->permissions->contains('slug', self::PERMISSION))
            ->pluck('scope_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    private function rulesQuery()
    {
        $q = MarketplaceCommissionRule::query()->with(['store:id,name', 'category:id,name', 'franchise:id,name', 'city:id,name']);

        return $this->isHq() ? $q : $q->whereIn('franchise_id', $this->ownFranchiseIds() ?: [0]);
    }

    public function save(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere(self::PERMISSION), 403);
        $hq = $this->isHq();
        $this->validate([
            'storeId' => ['nullable', 'integer', Rule::exists('stores', 'id')],
            'categoryId' => ['nullable', 'integer', Rule::exists('marketplace_categories', 'id')],
            'franchiseId' => [$hq ? 'nullable' : 'required', 'integer', Rule::exists('franchises', 'id')],
            'cityId' => ['nullable', 'integer', Rule::exists('cities', 'id')],
            'percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ], [], ['percent' => 'fee %', 'franchiseId' => 'franchise']);

        if (! $hq) {
            if (! in_array((int) $this->franchiseId, $this->ownFranchiseIds(), true)) {
                $this->addError('franchiseId', 'You can set rules for your own franchise only.');

                return;
            }
            $this->cityId = null;
            if ($this->storeId && (int) Store::whereKey($this->storeId)->value('franchise_id') !== (int) $this->franchiseId) {
                $this->addError('storeId', 'That shop is not in your franchise.');

                return;
            }
            $min = MarketplaceSettings::get('marketplace.commission.franchise_min');
            $max = MarketplaceSettings::get('marketplace.commission.franchise_max');
            if ($min === null || $max === null) {
                $this->addError('percent', 'Franchise rules are not open yet: Super Admin has not set the allowed range.');

                return;
            }
            if ((float) $this->percent < (float) $min || (float) $this->percent > (float) $max) {
                $this->addError('percent', "Choose between {$min}% and {$max}%.");

                return;
            }
        }

        $keys = ['store_id' => $this->storeId, 'marketplace_category_id' => $this->categoryId, 'franchise_id' => $this->franchiseId, 'city_id' => $this->cityId];
        $rule = MarketplaceCommissionRule::where($keys)->first();
        $old = $rule?->platform_fee_percent;
        $rule = MarketplaceCommissionRule::updateOrCreate($keys, ['platform_fee_percent' => round((float) $this->percent, 2), 'created_by' => auth()->id()]);
        ActivityLogger::logModel(auth()->user(), $rule, 'Shop commission rule saved', $keys + ['old' => $old, 'new' => $rule->platform_fee_percent]);

        $this->reset('storeId', 'categoryId', 'cityId', 'percent');
        $this->flashType = 'success';
        $this->flashMessage = 'Rule saved. It applies to orders completed from now on.';
    }

    public function delete(int $ruleId): void
    {
        $rule = $this->rulesQuery()->find($ruleId);
        abort_unless($rule, 404);
        ActivityLogger::logModel(auth()->user(), $rule, 'Shop commission rule removed', $rule->only(['store_id', 'marketplace_category_id', 'franchise_id', 'city_id', 'platform_fee_percent']));
        $rule->delete();
        $this->flashMessage = 'Rule removed.';
    }

    public function render()
    {
        $hq = $this->isHq();
        $franchiseIds = $hq ? null : $this->ownFranchiseIds();
        $stores = Store::where('module', Modules::COMMERCE)->when($franchiseIds !== null, fn ($q) => $q->whereIn('franchise_id', $franchiseIds ?: [0]))->orderBy('name')->get(['id', 'name']);
        $preview = $this->previewStoreId ? Store::find($this->previewStoreId) : null;

        return view('livewire.marketplace-commission.manage', [
            'hq' => $hq,
            'rules' => $this->rulesQuery()->orderByDesc('id')->get(),
            'stores' => $stores,
            'categories' => MarketplaceCategory::where('module', Modules::COMMERCE)->orderBy('name')->get(['id', 'name', 'parent_id']),
            'franchises' => Franchise::when($franchiseIds !== null, fn ($q) => $q->whereIn('id', $franchiseIds ?: [0]))->orderBy('name')->get(['id', 'name']),
            'cities' => $hq ? City::orderBy('name')->get(['id', 'name']) : collect(),
            'previewRate' => $preview && (! $franchiseIds || in_array($preview->franchise_id, $franchiseIds, true))
                ? app(MarketplaceCommissionResolver::class)->rateFor($preview, $this->previewCategoryId) : null,
            'bounds' => [MarketplaceSettings::get('marketplace.commission.franchise_min'), MarketplaceSettings::get('marketplace.commission.franchise_max')],
        ])->layout('layouts.admin', ['title' => 'Shop Commission']);
    }
}
