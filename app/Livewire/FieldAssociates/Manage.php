<?php

namespace App\Livewire\FieldAssociates;

use App\Models\FieldAssociate;
use App\Models\FieldAssociateCreditChange;
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
 * Admin → Users & Partners → Field associates (owner 2026-11-23, release A 2026-11-24). People hired to visit shops and
 * restaurants and sign them up. One screen: the rewards (Super Admin), add an associate (name + mobile), each one's
 * month (shops and restaurants signed up and live, 10-products, first orders, bonus, total) with the shops' progress
 * bars, pause / resume, fix a shop's credit (add, move, remove; a reason is required and kept in the shop's history),
 * and the month's sheet to pay from. Behind `field_associates.manage`, scoped to the admin's own franchises. No money
 * moves here: rewards are paid outside the app, and downloading the sheet does not mark anything paid.
 */
class Manage extends Component
{
    use HasCsvExport;

    private const PERMISSION = 'field_associates.manage';

    /** Bonus rows on the Rewards card (owner 2026-11-24: 10 / 20 / 30 / 40 shops). */
    public const BONUS_ROWS = 4;

    /** 0 = this month, 1 = last month, 2 = the month before. */
    public int $monthsAgo = 0;

    public string $name = '';

    public string $phone = '';

    public ?int $franchiseId = null;

    public ?int $openId = null;

    public ?int $assignStoreId = null;

    public string $assignReason = '';

    /** The shop whose credit is being moved or removed. */
    public ?int $changeStoreId = null;

    public string $changeAction = 'move';

    public ?int $moveToId = null;

    public string $changeReason = '';

    public string $rewardShopLive = '';

    public string $rewardProducts = '';

    public string $rewardFirstOrder = '';

    public string $maxPerShop = '';

    public string $firstOrderMin = '';

    /** @var list<array{shops: string, amount: string}> three bonus levels */
    public array $bonusLevels = [];

    public string $flash = '';

    public function mount(): void
    {
        $this->authorizeManage();
        $this->rewardShopLive = $this->plain(FieldAssociateTracker::rewardShopLive());
        $this->rewardProducts = $this->plain(FieldAssociateTracker::rewardProducts());
        $this->rewardFirstOrder = $this->plain(FieldAssociateTracker::rewardFirstOrder());
        $this->maxPerShop = $this->plain(FieldAssociateTracker::maxPerShop());
        $this->firstOrderMin = $this->plain(FieldAssociateTracker::firstOrderMin());
        $levels = FieldAssociateTracker::bonusLevels();
        for ($i = 0; $i < self::BONUS_ROWS; $i++) {
            $this->bonusLevels[$i] = isset($levels[$i]) ? ['shops' => (string) $levels[$i][0], 'amount' => $this->plain($levels[$i][1])] : ['shops' => '', 'amount' => ''];
        }
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

        $money = ['required', 'numeric', 'min:0', 'max:100000'];
        $this->validate([
            'rewardShopLive' => $money, 'rewardProducts' => $money, 'rewardFirstOrder' => $money, 'maxPerShop' => $money, 'firstOrderMin' => $money,
            'bonusLevels' => ['array', 'size:'.self::BONUS_ROWS],
            'bonusLevels.*.shops' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'bonusLevels.*.amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ], [
            '*.required' => 'Enter an amount (0 for none).',
            'bonusLevels.*.shops.integer' => 'Enter a number of shops.', 'bonusLevels.*.shops.min' => 'At least 1 shop.',
        ]);

        $levels = [];
        foreach ($this->bonusLevels as $i => $row) {
            [$shops, $amount] = [trim((string) ($row['shops'] ?? '')), trim((string) ($row['amount'] ?? ''))];
            if ($shops === '' && $amount === '') {
                continue;
            }
            if ($shops === '' || $amount === '') {
                $this->addError("bonusLevels.{$i}.".($shops === '' ? 'shops' : 'amount'), 'Fill both, or leave both empty.');

                continue;
            }
            if (isset($levels[(int) $shops])) {
                $this->addError("bonusLevels.{$i}.shops", 'Each level needs a different number of shops.');

                continue;
            }
            $levels[(int) $shops] = [(int) $shops, round((float) $amount, 2)];
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        ksort($levels);

        $old = [
            'shop_live' => FieldAssociateTracker::rewardShopLive(), 'products' => FieldAssociateTracker::rewardProducts(),
            'first_order' => FieldAssociateTracker::rewardFirstOrder(), 'max_per_shop' => FieldAssociateTracker::maxPerShop(),
            'first_order_min' => FieldAssociateTracker::firstOrderMin(), 'bonus_levels' => FieldAssociateTracker::bonusLevels(),
        ];
        $new = [
            'shop_live' => round((float) $this->rewardShopLive, 2), 'products' => round((float) $this->rewardProducts, 2),
            'first_order' => round((float) $this->rewardFirstOrder, 2), 'max_per_shop' => round((float) $this->maxPerShop, 2),
            'first_order_min' => round((float) $this->firstOrderMin, 2), 'bonus_levels' => array_values($levels),
        ];
        Setting::set(FieldAssociateTracker::REWARD_SHOP_LIVE, (string) $new['shop_live']);
        Setting::set(FieldAssociateTracker::REWARD_PRODUCTS, (string) $new['products']);
        Setting::set(FieldAssociateTracker::REWARD_FIRST_ORDER, (string) $new['first_order']);
        Setting::set(FieldAssociateTracker::MAX_PER_SHOP, (string) $new['max_per_shop']);
        Setting::set(FieldAssociateTracker::FIRST_ORDER_MIN, (string) $new['first_order_min']);
        Setting::set(FieldAssociateTracker::BONUS_LEVELS, json_encode($new['bonus_levels']));
        ActivityLogger::log(auth()->user(), 'field_associate_settings', 0, 'Field associate rewards changed', ['old' => $old, 'new' => $new]);
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
        $this->reset('assignStoreId', 'assignReason', 'changeStoreId', 'moveToId', 'changeReason');
    }

    /** Shops signed up in the last 60 days with no associate, in the admin's scope (the associate forgot their link). */
    private function assignableStores()
    {
        return app(AuthorizationService::class)->scopeQuery(Store::query(), auth()->user(), self::PERMISSION,
            ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'])
            ->whereNull('field_associate_id')->where('created_at', '>=', now()->subDays(60))->latest('id')->limit(100)->get(['id', 'name', 'module', 'created_at']);
    }

    private function reasonRule(): array
    {
        return ['required', 'string', 'min:5', 'max:500'];
    }

    public function assignStore(): void
    {
        $this->authorizeManage();
        $associate = $this->scoped()->findOrFail((int) $this->openId);
        $this->validate(['assignReason' => $this->reasonRule()], ['assignReason.*' => 'Say why (at least 5 letters), e.g. "Owner says Ravi visited on 12 Dec".']);
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

        // Never two associates on one shop: only a shop with no associate can be added (checked again under the lock).
        $done = DB::transaction(function () use ($store, $associate) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->first();
            if ($locked->field_associate_id !== null) {
                return false;
            }
            $locked->update(['field_associate_id' => $associate->id]);
            FieldAssociateCreditChange::create(['store_id' => $store->id, 'from_associate_id' => null, 'to_associate_id' => $associate->id,
                'changed_by' => auth()->id(), 'reason' => trim($this->assignReason)]);

            return true;
        });
        if (! $done) {
            $this->addError('assignStoreId', 'This shop already belongs to another associate.');

            return;
        }
        app(FieldAssociateTracker::class)->syncStore($store->fresh());
        ActivityLogger::logModel(auth()->user(), $store, "Shop credited to field associate {$associate->code}", ['reason' => trim($this->assignReason)]);
        $this->reset('assignStoreId', 'assignReason');
        $this->flash = "{$store->name} is now credited to {$associate->code}.";
    }

    public function startChange(int $storeId, string $action = 'move'): void
    {
        $this->authorizeManage();
        $this->scoped()->findOrFail((int) $this->openId)->stores()->findOrFail($storeId);
        $this->changeStoreId = $storeId;
        $this->changeAction = $action === 'remove' ? 'remove' : 'move';
        $this->reset('moveToId', 'changeReason');
        $this->resetErrorBag();
    }

    public function cancelChange(): void
    {
        $this->reset('changeStoreId', 'moveToId', 'changeReason');
    }

    /** Move a shop's credit to another associate, or remove it. A reason is required and saved in the shop's history. */
    public function saveChange(): void
    {
        $this->authorizeManage();
        $from = $this->scoped()->findOrFail((int) $this->openId);
        $store = $from->stores()->with('provider:id,user_id')->findOrFail((int) $this->changeStoreId);
        $this->validate(['changeReason' => $this->reasonRule(), 'changeAction' => ['required', 'in:move,remove']],
            ['changeReason.*' => 'Say why (at least 5 letters).']);

        // A reward from an earlier month may already be paid: only Super Admin changes that shop's credit.
        $paidBefore = FieldAssociateReward::where('store_id', $store->id)->where('earned_at', '<', now()->startOfMonth())->exists();
        if ($paidBefore && ! $this->isSuperAdmin()) {
            $this->addError('changeReason', 'This shop has rewards from an earlier month that may already be paid. Ask Super Admin to change it.');

            return;
        }

        $to = null;
        if ($this->changeAction === 'move') {
            $to = $this->scoped()->where('is_active', true)->whereKeyNot($from->id)->find((int) $this->moveToId);
            if (! $to) {
                $this->addError('moveToId', 'Choose the associate who really signed it up (active, in your city teams).');

                return;
            }
            if ($store->provider?->user_id === $to->user_id) {
                $this->addError('moveToId', 'An associate cannot be credited for their own shop.');

                return;
            }
        }

        $months = FieldAssociateReward::where('store_id', $store->id)->pluck('earned_at')->map(fn ($d) => Carbon::parse($d)->startOfMonth());
        DB::transaction(function () use ($store, $from, $to) {
            if ($to) {
                // The rewards follow the shop to the associate who really signed it up, with their amounts.
                FieldAssociateReward::where('store_id', $store->id)->update(['field_associate_id' => $to->id]);
            } else {
                FieldAssociateReward::where('store_id', $store->id)->delete();
            }
            $store->update(['field_associate_id' => $to?->id]);
            FieldAssociateCreditChange::create(['store_id' => $store->id, 'from_associate_id' => $from->id, 'to_associate_id' => $to?->id,
                'changed_by' => auth()->id(), 'reason' => trim($this->changeReason)]);
        });
        app(FieldAssociateTracker::class)->syncBonuses(collect(array_filter([$from->id, $to?->id])), $months->push(now()->startOfMonth())->unique(fn ($m) => $m->format('Y-m'))->values());

        ActivityLogger::logModel(auth()->user(), $store, $to ? "Shop credit moved from field associate {$from->code} to {$to->code}" : "Shop removed from field associate {$from->code}",
            ['reason' => trim($this->changeReason)]);
        $this->flash = $to ? "{$store->name} moved from {$from->code} to {$to->code}, with its rewards." : "{$store->name} removed from {$from->code}. Its rewards are removed too.";
        $this->reset('changeStoreId', 'moveToId', 'changeReason');
    }

    public function exportCsv()
    {
        $this->authorizeManage();
        $month = $this->month();
        // Test logins are never on the pay sheet.
        $query = $this->scoped()->with('user:id,name,phone,email')
            ->whereDoesntHave('user', fn ($q) => $q->where('email', 'like', '%'.\App\Console\Commands\TestAccountsCommand::DOMAIN));
        $totals = app(FieldAssociateTracker::class)->months((clone $query)->get(), $month);
        ActivityLogger::log(auth()->user(), 'field_associates_export', 0, 'Field associate rewards exported', ['month' => $month->format('Y-m')]);

        return $this->streamCsvExport(
            'field-associates-'.$month->format('Y-m').'.csv',
            $query,
            ['month', 'code', 'name', 'mobile', 'active', 'shops_signed_up', 'restaurants_signed_up', 'shops_live', 'restaurants_live', 'live_reward',
                'ten_products', 'ten_products_reward', 'first_orders', 'first_order_reward', 'complete_for_bonus', 'bonus', 'total_earned', 'status'],
            fn (FieldAssociate $a) => array_map([\App\Livewire\PartnerPage\Leads::class, 'csvSafe'], [
                $month->format('Y-m'), $a->code, $a->user?->name, $a->user?->phone, $a->is_active ? 'yes' : 'no',
                ...array_map(fn ($k) => $totals[$a->id][$k] ?? 0, ['signed_up_shops', 'signed_up_restaurants', 'live_shops', 'live_restaurants', 'live_amount',
                    'products', 'products_amount', 'first_orders', 'first_order_amount', 'complete', 'bonus', 'earned']),
                'earned, not yet approved or paid',
            ]),
        );
    }

    public function render(FieldAssociateTracker $tracker)
    {
        $associates = $this->scoped()->with(['user:id,name,phone,email', 'franchise:id,name'])->orderByDesc('is_active')->orderBy('id')->get();
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
            'openShops' => $open ? $tracker->shops($open, withHistory: true) : collect(),
            'assignable' => $open ? $this->assignableStores() : collect(),
            'moveTargets' => $open ? $associates->where('is_active', true)->where('id', '!=', $open->id)->values() : collect(),
            'franchiseOptions' => $this->franchises(),
            'canSetRewards' => $this->isSuperAdmin(),
            'rewardsSet' => FieldAssociateTracker::rewardShopLive() > 0 || FieldAssociateTracker::rewardProducts() > 0 || FieldAssociateTracker::rewardFirstOrder() > 0,
            'holdDays' => FieldAssociateTracker::holdDays(),
        ])->layout('layouts.admin', ['title' => 'Field associates']);
    }
}
