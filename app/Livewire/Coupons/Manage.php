<?php

namespace App\Livewire\Coupons;

use App\Models\City;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Models\Setting;
use App\Services\Coupons\CouponAdminService;
use App\Services\Coupons\CouponSettings;
use App\Support\SuperAdminGate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Super Admin / HQ coupon screens (coupon engine C2). Every mutation goes through CouponAdminService (audit log)
 * or SettingsAuditor, and every permission is re-checked server-side on each action — a hidden button is never
 * the guard. Permissions are checked with NO scope, so only HQ-scope holders (and Super Admin) pass; a
 * franchise-scoped role never reaches these screens.
 *
 * THUMB RULE (CLAUDE.md): there is deliberately no payment-method field, no "allow cash" toggle and no
 * per-booking override here. Coupons are online-only in the engine; the form just says so.
 *
 *   coupons.view     see the list and live usage
 *   coupons.manage   create, edit a non-live coupon, pause, archive
 *   coupons.approve  activate / resume, edit a live coupon, global scope
 *   Super Admin only  the global coupons.* settings (coupons.approve does not grant them)
 */
class Manage extends Component
{
    public ?int $editingId = null;

    public bool $showForm = false;

    public string $code = '';

    public string $name = '';

    public string $description = '';

    public string $discountType = 'percent';

    public string $value = '';

    public string $minOrderValue = '0';

    public string $maxDiscount = '';

    public string $usageLimit = '';

    public string $perUserLimit = '';

    public string $totalBudget = '';

    public string $dailyBudget = '';

    public string $campaignTag = '';

    public bool $stackableWithFlash = false;

    public string $validFrom = '';

    public string $validUntil = '';

    /** @var array<int, string> */
    public array $cityIds = [];

    /** @var array<int, string> */
    public array $categoryIds = [];

    /** @var array<int, string> */
    public array $subcategoryIds = [];

    /** @var array<int, string> */
    public array $serviceIds = [];

    public string $customerType = '';

    /** Explicit "Global / all eligible scope" — blank targeting never means everywhere (hardening §E). */
    public bool $globalScope = false;

    // Status change / archive modal
    public ?int $actionCouponId = null;

    public string $actionType = '';

    public string $actionReason = '';

    // Settings card
    public bool $settingEnabled = false;

    public string $settingHoldMinutes = '';

    // Coupon-entry controls (C3): per-surface switches + attempt rate limit.
    public bool $surfaceWizard = true;

    public bool $surfaceCart = true;

    public bool $surfaceCheckout = true;

    public bool $surfaceBundles = true;

    public string $attemptsPerCustomer = '';

    public string $attemptsPerIp = '';

    public string $attemptWindowSeconds = '';

    public string $flashType = 'success';

    public string $flashMessage = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermission('coupons.view'), 403, 'You do not have permission to view coupons.');

        $this->settingEnabled = CouponSettings::enabled();
        $this->settingHoldMinutes = (string) Setting::get('coupons.unpaid_hold_minutes', '');

        $this->surfaceWizard = CouponSettings::surfaceEnabled('wizard');
        $this->surfaceCart = CouponSettings::surfaceEnabled('cart');
        $this->surfaceCheckout = CouponSettings::surfaceEnabled('checkout');
        $this->surfaceBundles = CouponSettings::surfaceEnabled('bundles');
        $this->attemptsPerCustomer = (string) CouponSettings::attemptsPerCustomer();
        $this->attemptsPerIp = (string) CouponSettings::attemptsPerIp();
        $this->attemptWindowSeconds = (string) CouponSettings::attemptWindowSeconds();
    }

    private function allow(string $permission): void
    {
        abort_unless(auth()->user()->hasPermission($permission), 403, 'You do not have permission for this coupon action.');
    }

    public function newCoupon(): void
    {
        $this->allow('coupons.manage');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->allow('coupons.manage');
        $coupon = Coupon::with('targets')->findOrFail($id);
        $this->requireApproveIfLive($coupon);

        $this->resetForm();
        $this->editingId = $coupon->id;
        $this->code = $coupon->code;
        $this->name = $coupon->name;
        $this->description = (string) $coupon->description;
        $this->discountType = $coupon->discount_type;
        $this->value = (string) $coupon->value;
        $this->minOrderValue = (string) $coupon->min_order_value;
        $this->maxDiscount = (string) ($coupon->max_discount ?? '');
        $this->usageLimit = (string) ($coupon->usage_limit ?? '');
        $this->perUserLimit = (string) $coupon->per_user_limit;
        $this->totalBudget = (string) ($coupon->total_budget ?? '');
        $this->dailyBudget = (string) ($coupon->daily_budget ?? '');
        $this->campaignTag = (string) ($coupon->campaign_tag ?? '');
        $this->stackableWithFlash = (bool) $coupon->stackable_with_flash;
        $this->validFrom = $coupon->valid_from?->format('Y-m-d\TH:i') ?? '';
        $this->validUntil = $coupon->valid_until?->format('Y-m-d\TH:i') ?? '';

        $ids = fn (string $type) => $coupon->targets->where('target_type', $type)->where('operator', 'include')->pluck('target_id')->map(fn ($i) => (string) $i)->values()->all();
        $this->cityIds = $ids('city');
        $this->categoryIds = $ids('service_category');
        $this->subcategoryIds = $ids('service_subcategory');
        $this->serviceIds = $ids('service');
        $this->customerType = (string) ($coupon->targets->where('target_type', 'customer_type')->first()?->params['type'] ?? '');
        $this->globalScope = $coupon->targets->contains(fn ($t) => $t->target_type === 'global' && $t->operator === 'include');

        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->resetForm();
    }

    private function requireApproveIfLive(Coupon $coupon): void
    {
        // Changing the rules of a coupon customers can redeem right now is an approval-level act.
        if ($coupon->status === 'active') {
            $this->allow('coupons.approve');
        }
    }

    public function save(): void
    {
        $this->allow('coupons.manage');

        $coupon = $this->editingId ? Coupon::findOrFail($this->editingId) : null;
        if ($coupon) {
            $this->requireApproveIfLive($coupon);
        }

        $this->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'discountType' => ['required', 'in:flat,percent'],
            'value' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'minOrderValue' => ['required', 'numeric', 'min:0'],
            'maxDiscount' => ['nullable', 'numeric', 'gt:0'],
            'usageLimit' => ['nullable', 'integer', 'min:1'],
            'perUserLimit' => ['required', 'integer', 'min:1'],
            'totalBudget' => ['nullable', 'numeric', 'gt:0'],
            'dailyBudget' => ['nullable', 'numeric', 'gt:0'],
            'campaignTag' => ['nullable', 'string', 'max:60'],
            'validFrom' => ['nullable', 'date'],
            'validUntil' => ['nullable', 'date', 'after:validFrom'],
            'customerType' => ['nullable', 'in:new,returning,prime'],
        ], [], [
            'perUserLimit' => 'per-customer limit',
            'minOrderValue' => 'minimum order value',
            'dailyBudget' => 'daily cap',
            'totalBudget' => 'total budget',
        ]);

        $data = [
            'code' => trim($this->code),
            'name' => trim($this->name),
            'description' => trim($this->description) ?: null,
            'discount_type' => $this->discountType,
            'value' => $this->value,
            'min_order_value' => $this->minOrderValue,
            'max_discount' => $this->maxDiscount !== '' ? $this->maxDiscount : null,
            'usage_limit' => $this->usageLimit !== '' ? (int) $this->usageLimit : null,
            'per_user_limit' => (int) $this->perUserLimit,
            'total_budget' => $this->totalBudget !== '' ? $this->totalBudget : null,
            'daily_budget' => $this->dailyBudget !== '' ? $this->dailyBudget : null,
            'funding_mode' => 'hq',
            'campaign_tag' => trim($this->campaignTag) ?: null,
            'stackable_with_flash' => $this->stackableWithFlash,
            'valid_from' => $this->validFrom !== '' ? $this->validFrom : null,
            'valid_until' => $this->validUntil !== '' ? $this->validUntil : null,
            'module' => 'service',
            'franchise_id' => null, // HQ coupons only on these screens
        ];
        if (! $coupon) {
            $data['status'] = 'draft'; // activation is a separate, approval-level step
        }

        try {
            app(CouponAdminService::class)->save(auth()->user(), $data, $this->buildTargets(), $coupon);
        } catch (ValidationException $e) {
            $map = ['discount_type' => 'discountType', 'per_user_limit' => 'perUserLimit', 'total_budget' => 'totalBudget', 'daily_budget' => 'dailyBudget', 'usage_limit' => 'usageLimit', 'funding_mode' => 'code'];
            foreach ($e->errors() as $field => $messages) {
                $this->addError($map[$field] ?? $field, $messages[0]);
            }

            return;
        }

        $this->resetForm();
        $this->flashType = 'success';
        $this->flashMessage = $coupon ? 'Coupon updated.' : 'Coupon created as a draft. Activate it when ready.';
    }

    /** @return array<int, array{target_type: string, target_id?: ?int, operator: string, params?: ?array}> */
    private function buildTargets(): array
    {
        $rows = [];
        foreach (['city' => $this->cityIds, 'service_category' => $this->categoryIds, 'service_subcategory' => $this->subcategoryIds, 'service' => $this->serviceIds] as $type => $ids) {
            foreach (array_unique(array_map('intval', $ids)) as $id) {
                $rows[] = ['target_type' => $type, 'target_id' => $id, 'operator' => 'include'];
            }
        }
        if ($this->globalScope) {
            $rows[] = ['target_type' => 'global', 'target_id' => null, 'operator' => 'include'];
        }
        if ($this->customerType !== '') {
            $rows[] = ['target_type' => 'customer_type', 'target_id' => null, 'operator' => 'include', 'params' => ['type' => $this->customerType]];
        }

        return $rows;
    }

    public function startAction(int $id, string $type): void
    {
        abort_unless(in_array($type, ['activate', 'pause', 'archive'], true), 422);
        $this->allow($type === 'activate' ? 'coupons.approve' : 'coupons.manage');
        Coupon::findOrFail($id);

        $this->actionCouponId = $id;
        $this->actionType = $type;
        $this->actionReason = '';
    }

    public function confirmAction(): void
    {
        $type = $this->actionType;
        abort_unless(in_array($type, ['activate', 'pause', 'archive'], true), 422);
        $this->allow($type === 'activate' ? 'coupons.approve' : 'coupons.manage');

        $this->validate(['actionReason' => ['required', 'string', 'min:3', 'max:500']], [], ['actionReason' => 'reason']);

        $coupon = Coupon::findOrFail($this->actionCouponId);
        $service = app(CouponAdminService::class);

        if ($type === 'archive') {
            $service->archive(auth()->user(), $coupon, $this->actionReason);
            $msg = 'Coupon archived.';
        } else {
            $service->setStatus(auth()->user(), $coupon, $type === 'activate' ? 'active' : 'paused', $this->actionReason);
            $msg = $type === 'activate' ? 'Coupon is now active.' : 'Coupon paused.';
        }

        $this->cancelAction();
        $this->flashType = 'success';
        $this->flashMessage = $msg;
    }

    public function cancelAction(): void
    {
        $this->actionCouponId = null;
        $this->actionType = '';
        $this->actionReason = '';
    }

    public function saveSettings(): void
    {
        // Global settings are Super Admin only (hardening §J) — checked again inside CouponSettings::save().
        abort_unless(SuperAdminGate::allows(auth()->user()), 403, 'Only a Super Admin can change global coupon settings.');

        $this->validate(['settingHoldMinutes' => ['nullable', 'integer', 'min:1', 'max:1440']], [], ['settingHoldMinutes' => 'unpaid hold minutes']);

        $changed = CouponSettings::save(auth()->user(), $this->settingEnabled, $this->settingHoldMinutes);

        $this->flashType = 'success';
        $this->flashMessage = $changed ? 'Coupon settings saved.' : 'Nothing changed.';
    }

    public function saveEntryControls(): void
    {
        // Super Admin only (C3 items 5/6) — checked again inside CouponSettings::saveEntryControls().
        abort_unless(SuperAdminGate::allows(auth()->user()), 403, 'Only a Super Admin can change global coupon settings.');

        $this->validate([
            'attemptsPerCustomer' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'attemptsPerIp' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'attemptWindowSeconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
        ], [], ['attemptsPerCustomer' => 'attempts per customer', 'attemptsPerIp' => 'attempts per IP', 'attemptWindowSeconds' => 'window seconds']);

        $int = fn (string $v): ?int => trim($v) === '' ? null : (int) $v;

        $changed = CouponSettings::saveEntryControls(
            auth()->user(),
            ['wizard' => $this->surfaceWizard, 'cart' => $this->surfaceCart, 'checkout' => $this->surfaceCheckout, 'bundles' => $this->surfaceBundles],
            $int($this->attemptsPerCustomer),
            $int($this->attemptsPerIp),
            $int($this->attemptWindowSeconds),
        );

        $this->flashType = 'success';
        $this->flashMessage = $changed ? 'Coupon entry controls saved.' : 'Nothing changed.';
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'showForm', 'code', 'name', 'description', 'value', 'maxDiscount', 'usageLimit', 'perUserLimit', 'totalBudget', 'dailyBudget', 'campaignTag', 'stackableWithFlash', 'validFrom', 'validUntil', 'cityIds', 'categoryIds', 'subcategoryIds', 'serviceIds', 'customerType', 'globalScope']);
        $this->discountType = 'percent';
        $this->minOrderValue = '0';
        $this->resetErrorBag();
    }

    public function render()
    {
        $user = auth()->user();
        $coupons = Coupon::orderByDesc('id')->get();

        $todayStart = now('Asia/Kolkata')->startOfDay()->utc();
        $usage = CouponUsage::selectRaw('coupon_id, status, COUNT(*) as n, COALESCE(SUM(discount_applied),0) as amt')
            ->groupBy('coupon_id', 'status')->get()->groupBy('coupon_id');
        $today = CouponUsage::selectRaw('coupon_id, COALESCE(SUM(discount_applied),0) as amt')
            ->whereIn('status', ['reserved', 'confirmed'])->where('reserved_at', '>=', $todayStart)
            ->groupBy('coupon_id')->pluck('amt', 'coupon_id');

        $rows = $coupons->map(function (Coupon $c) use ($usage, $today) {
            $u = $usage->get($c->id, collect())->keyBy('status');

            return [
                'coupon' => $c,
                'reserved_n' => (int) ($u['reserved']->n ?? 0),
                'confirmed_n' => (int) ($u['confirmed']->n ?? 0),
                'released_n' => (int) ($u['released']->n ?? 0),
                'consumed_n' => (int) ($u['consumed']->n ?? 0),
                'spent' => round((float) ($u['reserved']->amt ?? 0) + (float) ($u['confirmed']->amt ?? 0), 2),
                'today' => (float) ($today[$c->id] ?? 0),
            ];
        });

        return view('livewire.coupons.manage', [
            'rows' => $rows,
            'canManage' => $user->hasPermission('coupons.manage'),
            'canApprove' => $user->hasPermission('coupons.approve'),
            'isSuperAdmin' => SuperAdminGate::allows($user),
            'cities' => $this->showForm ? City::orderBy('name')->get(['id', 'name']) : collect(),
            'categories' => $this->showForm ? ServiceCategory::orderBy('name')->get(['id', 'name']) : collect(),
            'subcategories' => $this->showForm ? ServiceSubcategory::orderBy('name')->get(['id', 'name']) : collect(),
            'services' => $this->showForm ? Service::orderBy('name')->get(['id', 'name']) : collect(),
            'available' => CouponSettings::available(),
        ])->layout('layouts.admin', ['title' => 'Coupons']);
    }
}
