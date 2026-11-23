<?php

namespace App\Livewire\FieldAssociates;

use App\Models\FieldAssociate;
use App\Models\FieldAssociateReward;
use App\Models\Franchise;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AuthorizationService;
use App\Services\FieldAssociates\FieldAssociateTracker;
use App\Support\Concerns\HasCsvExport;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Admin → Users & Partners → Field associates (owner 2026-11-23). People hired to visit shops and restaurants and
 * sign them up. One screen: add an associate (name + mobile), see each one's month (signed up, live, first orders,
 * rewards) with the shops' progress bars, pause / resume, fix a shop's credit, and download the month as a sheet to
 * pay from. Behind `field_associates.manage`, scoped to the admin's own franchises; the reward amounts and the
 * monthly target are global and Super Admin only. No money moves here: rewards are paid outside the app.
 */
class Manage extends Component
{
    use HasCsvExport;

    private const PERMISSION = 'field_associates.manage';

    /** 0 = this month, 1 = last month, 2 = the month before. */
    public int $monthsAgo = 0;

    public string $name = '';

    public string $phone = '';

    public ?int $franchiseId = null;

    public ?int $openId = null;

    public ?int $assignStoreId = null;

    public string $rewardShopLive = '';

    public string $rewardFirstOrder = '';

    public string $monthlyTarget = '';

    public string $flash = '';

    public function mount(): void
    {
        $this->authorizeManage();
        $this->rewardShopLive = $this->plain(FieldAssociateTracker::rewardShopLive());
        $this->rewardFirstOrder = $this->plain(FieldAssociateTracker::rewardFirstOrder());
        $this->monthlyTarget = (string) FieldAssociateTracker::monthlyTarget();
        $this->franchiseId = $this->franchises()->count() === 1 ? $this->franchises()->first()->id : null;
    }

    private function plain(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermissionAnywhere(self::PERMISSION), 403, 'You do not have permission to manage field associates.');
    }

    private function isSuperAdmin(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    private function scoped(): Builder
    {
        return app(AuthorizationService::class)->scopeQuery(FieldAssociate::query(), auth()->user(), self::PERMISSION,
            ['franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id']);
    }

    private function franchises()
    {
        return app(AuthorizationService::class)->scopeQuery(Franchise::query(), auth()->user(), self::PERMISSION,
            ['franchise_id' => 'id', 'city_id' => 'city_id', 'country_id' => 'country_id'])->orderBy('name')->get(['id', 'name']);
    }

    private function month(): Carbon
    {
        return Carbon::now()->startOfMonth()->subMonthsNoOverflow(max(0, min(2, $this->monthsAgo)));
    }

    public function showMonth(int $monthsAgo): void
    {
        $this->monthsAgo = max(0, min(2, $monthsAgo));
    }

    public function saveRewards(): void
    {
        $this->authorizeManage();
        abort_unless($this->isSuperAdmin(), 403, 'Only Super Admin sets the rewards.');

        $this->validate([
            'rewardShopLive' => ['required', 'numeric', 'min:0', 'max:100000'],
            'rewardFirstOrder' => ['required', 'numeric', 'min:0', 'max:100000'],
            'monthlyTarget' => ['required', 'integer', 'min:0', 'max:10000'],
        ], [
            'rewardShopLive.required' => 'Enter the reward (0 for none).', 'rewardFirstOrder.required' => 'Enter the reward (0 for none).',
            'monthlyTarget.required' => 'Enter the target (0 for none).',
        ]);

        $old = [FieldAssociateTracker::rewardShopLive(), FieldAssociateTracker::rewardFirstOrder(), FieldAssociateTracker::monthlyTarget()];
        Setting::set(FieldAssociateTracker::REWARD_SHOP_LIVE, (string) round((float) $this->rewardShopLive, 2));
        Setting::set(FieldAssociateTracker::REWARD_FIRST_ORDER, (string) round((float) $this->rewardFirstOrder, 2));
        Setting::set(FieldAssociateTracker::MONTHLY_TARGET, (string) (int) $this->monthlyTarget);
        ActivityLogger::log(auth()->user(), 'field_associate_settings', 0, 'Field associate rewards changed', [
            'old' => ['shop_live' => $old[0], 'first_order' => $old[1], 'monthly_target' => $old[2]],
            'new' => ['shop_live' => (float) $this->rewardShopLive, 'first_order' => (float) $this->rewardFirstOrder, 'monthly_target' => (int) $this->monthlyTarget],
        ]);
        $this->flash = 'Rewards saved. New amounts apply to rewards earned from now on.';
    }

    public function addAssociate(): void
    {
        $this->authorizeManage();
        $this->flash = '';
        $franchiseIds = $this->franchises()->pluck('id')->all();

        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:20'],
            'franchiseId' => ['required', 'integer', 'in:'.implode(',', $franchiseIds ?: [0])],
        ], [
            'name.required' => 'Enter the associate\'s name.', 'phone.required' => 'Enter their mobile number.',
            'franchiseId.required' => 'Choose the city team they work for.', 'franchiseId.in' => 'Choose the city team they work for.',
        ]);
        if (! PhoneNumber::looksValid($this->phone)) {
            $this->addError('phone', 'Enter a valid 10-digit mobile number.');

            return;
        }

        $national = PhoneNumber::national($this->phone);
        $user = User::where('phone', $national)->first();
        if ($user && ! in_array($user->role, ['customer', 'provider'], true)) {
            $this->addError('phone', 'This number belongs to a staff account. Use the associate\'s own mobile number.');

            return;
        }
        if ($user && $user->status === 'suspended') {
            $this->addError('phone', 'This account is suspended.');

            return;
        }
        if ($user && ($existing = FieldAssociate::where('user_id', $user->id)->first())) {
            $this->addError('phone', "This number is already field associate {$existing->code}.");

            return;
        }

        $associate = DB::transaction(function () use ($user, $national) {
            $user ??= User::create([
                'uuid' => (string) Str::uuid(), 'name' => trim($this->name), 'phone' => $national, 'password' => null,
                'role' => 'customer', 'status' => 'active', 'franchise_id' => $this->franchiseId, 'preferred_language' => 'en',
            ]);
            $associate = FieldAssociate::create(['user_id' => $user->id, 'franchise_id' => $this->franchiseId, 'code' => 'FA-'.Str::random(8), 'is_active' => true]);
            $associate->update(['code' => FieldAssociate::codeFor($associate->id)]);

            return $associate;
        });

        ActivityLogger::log(auth()->user(), 'field_associate', $associate->id, "Field associate {$associate->code} added", ['user_id' => $associate->user_id, 'franchise_id' => $associate->franchise_id]);
        $this->reset('name', 'phone');
        $this->flash = "{$associate->code} added. They sign in on the website with mobile {$national} (the first time, a code to that phone lets them set a password), then open Account → My field work.";
    }

    public function toggleActive(int $id): void
    {
        $this->authorizeManage();
        $associate = $this->scoped()->findOrFail($id);
        $associate->update(['is_active' => ! $associate->is_active]);
        ActivityLogger::log(auth()->user(), 'field_associate', $associate->id, "Field associate {$associate->code} ".($associate->is_active ? 'resumed' : 'paused'));
        $this->flash = "{$associate->code} ".($associate->is_active ? 'resumed: their link works again.' : 'paused: their link no longer adds shops to them.');
    }

    public function open(int $id): void
    {
        $this->openId = $this->openId === $id ? null : $this->scoped()->findOrFail($id)->id;
        $this->assignStoreId = null;
    }

    /** Shops signed up in the last 60 days with no associate, in the admin's scope (the associate forgot their link). */
    private function assignableStores()
    {
        return app(AuthorizationService::class)->scopeQuery(Store::query(), auth()->user(), self::PERMISSION,
            ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'])
            ->whereNull('field_associate_id')->where('created_at', '>=', now()->subDays(60))->latest('id')->limit(100)->get(['id', 'name', 'module', 'created_at']);
    }

    public function assignStore(): void
    {
        $this->authorizeManage();
        $associate = $this->scoped()->findOrFail((int) $this->openId);
        $store = $this->assignableStores()->firstWhere('id', (int) $this->assignStoreId);
        if (! $store) {
            $this->addError('assignStoreId', 'Choose a shop from the list.');

            return;
        }
        $store = Store::with('provider:id,user_id')->find($store->id);
        if ($store->provider?->user_id === $associate->user_id) {
            $this->addError('assignStoreId', 'An associate cannot be credited for their own shop.');

            return;
        }

        $store->update(['field_associate_id' => $associate->id]);
        app(FieldAssociateTracker::class)->syncStore($store);
        ActivityLogger::logModel(auth()->user(), $store, "Shop credited to field associate {$associate->code}");
        $this->assignStoreId = null;
        $this->flash = "{$store->name} is now credited to {$associate->code}.";
    }

    public function removeStore(int $storeId): void
    {
        $this->authorizeManage();
        $associate = $this->scoped()->findOrFail((int) $this->openId);
        $store = $associate->stores()->findOrFail($storeId);

        DB::transaction(function () use ($store) {
            FieldAssociateReward::where('store_id', $store->id)->delete();
            $store->update(['field_associate_id' => null]);
        });
        ActivityLogger::logModel(auth()->user(), $store, "Shop removed from field associate {$associate->code}");
        $this->flash = "{$store->name} removed from {$associate->code}. Its rewards are removed too.";
    }

    public function exportCsv()
    {
        $this->authorizeManage();
        $month = $this->month();
        $query = $this->scoped()->with('user:id,name,phone');
        $totals = app(FieldAssociateTracker::class)->months((clone $query)->get(), $month);
        ActivityLogger::log(auth()->user(), 'field_associates_export', 0, 'Field associate rewards exported', ['month' => $month->format('Y-m')]);

        return $this->streamCsvExport(
            'field-associates-'.$month->format('Y-m').'.csv',
            $query,
            ['month', 'code', 'name', 'mobile', 'active', 'shops_signed_up', 'shops_live', 'shop_live_reward', 'first_orders', 'first_order_reward', 'total_to_pay'],
            fn (FieldAssociate $a) => array_map([\App\Livewire\PartnerPage\Leads::class, 'csvSafe'], [
                $month->format('Y-m'), $a->code, $a->user?->name, $a->user?->phone, $a->is_active ? 'yes' : 'no',
                $totals[$a->id]['signed_up'] ?? 0, $totals[$a->id]['live'] ?? 0, $totals[$a->id]['live_amount'] ?? 0,
                $totals[$a->id]['first_orders'] ?? 0, $totals[$a->id]['first_order_amount'] ?? 0, $totals[$a->id]['earned'] ?? 0,
            ]),
        );
    }

    public function render(FieldAssociateTracker $tracker)
    {
        $associates = $this->scoped()->with(['user:id,name,phone', 'franchise:id,name'])->orderByDesc('is_active')->orderBy('id')->get();
        foreach ($associates as $a) {
            $tracker->syncAssociate($a);
        }
        $month = $this->month();
        $open = $this->openId ? $associates->firstWhere('id', $this->openId) : null;

        return view('livewire.field-associates.manage', [
            'associates' => $associates,
            'totals' => $tracker->months($associates, $month),
            'month' => $month,
            'open' => $open,
            'openShops' => $open ? $tracker->shops($open) : collect(),
            'assignable' => $open ? $this->assignableStores() : collect(),
            'franchiseOptions' => $this->franchises(),
            'canSetRewards' => $this->isSuperAdmin(),
            'target' => FieldAssociateTracker::monthlyTarget(),
            'rewardsSet' => FieldAssociateTracker::rewardShopLive() > 0 || FieldAssociateTracker::rewardFirstOrder() > 0,
        ])->layout('layouts.admin', ['title' => 'Field associates']);
    }
}
