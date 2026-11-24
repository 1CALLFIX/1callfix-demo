<?php

namespace App\Services\FieldAssociates;

use App\Models\FieldAssociate;
use App\Models\FieldAssociateBonus;
use App\Models\FieldAssociateReward;
use App\Models\MarketplaceOrder;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\MarketplaceSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Field associates (owner 2026-11-23, plan 2026-11-24): how far each shop or restaurant an associate signed up has
 * come, and what they earned.
 *
 * Three rewards per shop or restaurant (amounts set by Super Admin on Admin → Field associates; owner's plan ₹25 / ₹25 /
 * ₹50, at most ₹100 per shop):
 *  - "Live": the KYC is approved and the shop is approved (an admin can approve a shop only after its KYC).
 *  - "10 products": the shop is live and has 10 approved, active products or menu items (the office rejects junk).
 *  - "First order": the first genuine order: completed and paid, at least the minimum order value, never placed by the
 *    shop's owner, the associate or (for a real associate) a test login, and a customer who was not already the first
 *    order of another of this associate's shops. It is written only once the return window has passed (earned on that
 *    day), and a fully refunded order cancels it, so the shop's next genuine order can earn it instead.
 *
 * Plus a monthly bonus: the highest level reached (e.g. 10 / 20 / 30 shops → ₹500 / ₹1,000 / ₹1,500, not added
 * together), counting shops that became live with 10 approved products in that month.
 *
 * A test shop (its owner is an @1callfix.test login) only ever earns for a test associate and the other way round, so
 * the pay sheet never holds test data. Every reward row keeps the amount at the time it was earned. Writing is
 * idempotent (unique store + kind) and runs on shop approval, product saves, order completion, the daily
 * `field-associates:sync`, and whenever the tracker pages open. Payment happens outside the app; no money moves here.
 */
class FieldAssociateTracker
{
    public const REWARD_SHOP_LIVE = 'field_associates.reward_shop_live';

    public const REWARD_PRODUCTS = 'field_associates.reward_products';

    public const REWARD_FIRST_ORDER = 'field_associates.reward_first_order';

    public const MAX_PER_SHOP = 'field_associates.max_per_shop';

    public const FIRST_ORDER_MIN = 'field_associates.first_order_min';

    public const BONUS_LEVELS = 'field_associates.bonus_levels';

    /** Before release A: shops live per month to aim for. Used for the bar only when no bonus level is set. */
    public const MONTHLY_TARGET = 'field_associates.monthly_target';

    /** Approved products or menu items a shop needs for the "10 products" reward (owner's plan). */
    public const PRODUCTS_NEEDED = 10;

    public static function rewardShopLive(): float
    {
        return self::amount(self::REWARD_SHOP_LIVE);
    }

    public static function rewardProducts(): float
    {
        return self::amount(self::REWARD_PRODUCTS);
    }

    public static function rewardFirstOrder(): float
    {
        return self::amount(self::REWARD_FIRST_ORDER);
    }

    /** At most this much per shop or restaurant from the three rewards; 0 = no limit. */
    public static function maxPerShop(): float
    {
        return self::amount(self::MAX_PER_SHOP);
    }

    /** The first order counts only from this order value (₹); 0 = any value. */
    public static function firstOrderMin(): float
    {
        return self::amount(self::FIRST_ORDER_MIN);
    }

    private static function amount(string $key): float
    {
        return max(0.0, (float) Setting::get($key, 0));
    }

    public static function monthlyTarget(): int
    {
        return max(0, (int) Setting::get(self::MONTHLY_TARGET, 0));
    }

    /**
     * Bonus levels, smallest first: [[shops, amount], ...]. Blank or invalid rows are left out.
     *
     * @return list<array{0: int, 1: float}>
     */
    public static function bonusLevels(): array
    {
        $raw = json_decode((string) Setting::get(self::BONUS_LEVELS, '[]'), true);
        $levels = [];
        foreach (is_array($raw) ? $raw : [] as $row) {
            if (is_array($row) && count($row) === 2 && (int) $row[0] > 0 && (float) $row[1] >= 0) {
                $levels[(int) $row[0]] = [(int) $row[0], round((float) $row[1], 2)];
            }
        }
        ksort($levels);

        return array_values($levels);
    }

    /** Days a first order waits before it pays: the shop return window + 1 (a returned order never pays). */
    public static function holdDays(): int
    {
        return max(0, (int) MarketplaceSettings::get('marketplace.return_window_days')) + 1;
    }

    public function syncStore(Store $store): void
    {
        $this->sync(collect([$store]));
    }

    public function syncAssociate(FieldAssociate $associate): void
    {
        $this->sync($associate->stores()->get());
    }

    /** Everything, every associate (the daily `field-associates:sync`). */
    public function syncAll(): int
    {
        $n = 0;
        Store::query()->whereNotNull('field_associate_id')->chunkById(200, function ($stores) use (&$n) {
            $this->sync($stores);
            $n += $stores->count();
        });

        return $n;
    }

    /** @param  Collection<int, Store>  $stores */
    public function sync(Collection $stores): void
    {
        $stores = $stores->filter(fn (Store $s) => $s->field_associate_id !== null)->values();
        if ($stores->isEmpty()) {
            return;
        }
        $stores = (new \Illuminate\Database\Eloquent\Collection($stores->all()))
            ->loadMissing(['provider:id,user_id,kyc_status', 'provider.user:id,email', 'fieldAssociate:id,user_id', 'fieldAssociate.user:id,email']);
        // A test shop earns only for a test associate, and a real shop only for a real associate.
        $stores = $stores->filter(fn (Store $s) => FieldAssociate::isTestUser($s->provider?->user) === FieldAssociate::isTestUser($s->fieldAssociate?->user))->values();
        if ($stores->isEmpty()) {
            return;
        }

        $this->cancelRefundedFirstOrders($stores);

        $have = FieldAssociateReward::query()->whereIn('store_id', $stores->pluck('id'))->get(['store_id', 'kind', 'amount'])->groupBy('store_id');
        $has = fn (Store $s, string $kind) => ($have[$s->id] ?? collect())->contains('kind', $kind);
        $approved = Product::query()->whereIn('store_id', $stores->pluck('id'))->where('is_approved', true)->where('is_active', true)
            ->selectRaw('store_id, COUNT(*) as n')->groupBy('store_id')->pluck('n', 'store_id');
        $firstOrders = $this->firstOrders($stores->reject(fn (Store $s) => $has($s, FieldAssociateReward::FIRST_ORDER)));

        $cap = self::maxPerShop();
        $now = now();
        $rows = [];
        foreach ($stores as $store) {
            $total = (float) ($have[$store->id] ?? collect())->sum('amount');
            $add = function (string $kind, float $amount, Carbon $at, ?array $order = null) use (&$rows, &$total, $store, $cap, $now) {
                $amount = $cap > 0 ? max(0.0, min($amount, round($cap - $total, 2))) : $amount;
                $total += $amount;
                $rows[] = ['field_associate_id' => $store->field_associate_id, 'store_id' => $store->id, 'kind' => $kind,
                    'order_id' => $order['order_id'] ?? null, 'customer_id' => $order['customer_id'] ?? null,
                    'amount' => $amount, 'earned_at' => $at, 'created_at' => $now, 'updated_at' => $now];
            };

            if ($store->approved_at === null) {
                continue;
            }
            if (! $has($store, FieldAssociateReward::SHOP_LIVE)) {
                $add(FieldAssociateReward::SHOP_LIVE, self::rewardShopLive(), $store->approved_at);
            }
            if (! $has($store, FieldAssociateReward::PRODUCTS) && (int) ($approved[$store->id] ?? 0) >= self::PRODUCTS_NEEDED) {
                $add(FieldAssociateReward::PRODUCTS, self::rewardProducts(), $now->copy()->max($store->approved_at));
            }
            if (isset($firstOrders[$store->id])) {
                $add(FieldAssociateReward::FIRST_ORDER, self::rewardFirstOrder(), $firstOrders[$store->id]['earned_at'], $firstOrders[$store->id]);
            }
        }

        if ($rows !== []) {
            // Two people opening the page at once write the same row: the unique key keeps exactly one.
            FieldAssociateReward::query()->insertOrIgnore($rows);
        }

        $months = collect($rows)->map(fn ($r) => Carbon::parse($r['earned_at'])->startOfMonth())
            ->push(now()->startOfMonth())->unique(fn (Carbon $m) => $m->format('Y-m'));
        $this->syncBonuses($stores->pluck('field_associate_id')->unique()->values(), $months->values());
    }

    /** A first order that was fully refunded (or is no longer completed) no longer pays; the next genuine order can. */
    private function cancelRefundedFirstOrders(Collection $stores): void
    {
        $gone = FieldAssociateReward::query()->whereIn('store_id', $stores->pluck('id'))->where('kind', FieldAssociateReward::FIRST_ORDER)
            ->whereNotNull('order_id')
            ->whereIn('order_id', MarketplaceOrder::query()->select('id')->where(fn ($q) => $q->where('payment_status', 'refunded')->orWhere('status', '!=', 'completed')))
            ->get();
        foreach ($gone as $reward) {
            $reward->delete();
            ActivityLogger::log(null, 'field_associate_reward', $reward->id, 'First-order reward cancelled: the order was refunded', [
                'store_id' => $reward->store_id, 'order_id' => $reward->order_id, 'amount' => (float) $reward->amount, 'earned_at' => $reward->earned_at?->toDateString(),
            ]);
        }
    }

    /**
     * store id => its first genuine order (see the class comment), once the return window has passed.
     *
     * @param  Collection<int, Store>  $stores
     * @return array<int, array{order_id: int, customer_id: int, earned_at: Carbon}>
     */
    private function firstOrders(Collection $stores): array
    {
        $stores = $stores->filter(fn (Store $s) => $s->approved_at !== null);
        if ($stores->isEmpty()) {
            return [];
        }

        $hold = self::holdDays();
        $orders = MarketplaceOrder::query()->whereIn('store_id', $stores->pluck('id'))
            ->where('status', 'completed')->whereNotNull('completed_at')->where('completed_at', '<=', now()->subDays($hold))
            ->whereIn('payment_status', ['paid', 'partially_refunded'])
            ->when(self::firstOrderMin() > 0, fn ($q) => $q->where('total_amount', '>=', self::firstOrderMin()))
            ->orderBy('completed_at')->orderBy('id')->get(['id', 'store_id', 'customer_id', 'completed_at']);
        if ($orders->isEmpty()) {
            return [];
        }

        $testCustomers = User::query()->whereIn('id', $orders->pluck('customer_id')->unique())
            ->where('email', 'like', '%'.\App\Console\Commands\TestAccountsCommand::DOMAIN)->pluck('id')->flip();
        // One customer can be the first order of only one of an associate's shops.
        $used = FieldAssociateReward::query()->whereIn('field_associate_id', $stores->pluck('field_associate_id')->unique())
            ->where('kind', FieldAssociateReward::FIRST_ORDER)->whereNotNull('customer_id')->get(['field_associate_id', 'customer_id'])
            ->map(fn ($r) => $r->field_associate_id.':'.$r->customer_id)->flip()->all();

        $byStore = $stores->keyBy('id');
        $first = [];
        foreach ($orders as $order) {
            $store = $byStore[$order->store_id];
            if (isset($first[$store->id])) {
                continue;
            }
            $customer = (int) $order->customer_id;
            $realAssociate = ! FieldAssociate::isTestUser($store->fieldAssociate?->user);
            if (in_array($customer, array_filter([$store->provider?->user_id, $store->fieldAssociate?->user_id]), true)
                || ($realAssociate && isset($testCustomers[$customer]))
                || isset($used[$store->field_associate_id.':'.$customer])) {
                continue;
            }
            $used[$store->field_associate_id.':'.$customer] = true;
            $first[$store->id] = ['order_id' => $order->id, 'customer_id' => $customer, 'earned_at' => Carbon::parse($order->completed_at)->addDays($hold)];
        }

        return $first;
    }

    /**
     * Shops or restaurants that became complete (live + 10 approved products) in the month, per associate: the later of
     * the two rewards falls in that month.
     *
     * @return array<int, int> associate id => count
     */
    public function completeShops(Collection $associateIds, Carbon $month): array
    {
        $to = $month->copy()->endOfMonth();
        $from = $month->copy()->startOfMonth();

        return FieldAssociateReward::query()->whereIn('field_associate_id', $associateIds)
            ->whereIn('kind', [FieldAssociateReward::SHOP_LIVE, FieldAssociateReward::PRODUCTS])->where('earned_at', '<=', $to)
            ->get(['field_associate_id', 'store_id', 'kind', 'earned_at'])
            ->groupBy('store_id')
            ->filter(fn (Collection $rows) => $rows->pluck('kind')->unique()->count() === 2 && $rows->max('earned_at')->gte($from))
            ->map(fn (Collection $rows) => $rows->first()->field_associate_id)
            ->countBy()->all();
    }

    /** The highest bonus level reached for each month, one row per associate and month (amount fixed when reached). */
    public function syncBonuses(Collection $associateIds, Collection $months): void
    {
        $levels = self::bonusLevels();
        foreach ($months as $month) {
            $month = $month->copy()->startOfMonth();
            $counts = $this->completeShops($associateIds, $month);
            $rows = FieldAssociateBonus::query()->whereIn('field_associate_id', $associateIds)->whereDate('month', $month->toDateString())->get()->keyBy('field_associate_id');
            foreach ($associateIds as $id) {
                $shops = (int) ($counts[$id] ?? 0);
                $reached = collect($levels)->filter(fn ($l) => $shops >= $l[0])->last();
                $row = $rows[$id] ?? null;
                if ($reached === null) {
                    $row?->delete();

                    continue;
                }
                if ($row && (int) $row->level_shops === $reached[0]) {
                    (int) $row->shops === $shops || $row->update(['shops' => $shops]);

                    continue;
                }
                $values = ['shops' => $shops, 'level_shops' => $reached[0], 'amount' => $reached[1], 'earned_at' => now()->min($month->copy()->endOfMonth())];
                if ($row) {
                    $row->update($values);

                    continue;
                }
                try {
                    FieldAssociateBonus::query()->create(['field_associate_id' => $id, 'month' => $month->toDateString()] + $values);
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    // Written at the same moment by another request: one row per associate and month is kept.
                }
            }
        }
    }

    /**
     * The five steps of one shop, for its progress bar: signed up → 10 products (reward) → KYC → live (reward) → first
     * order (reward). $rewards = this shop's reward rows keyed by kind.
     *
     * @return array{steps: list<array{key: string, label: string, emoji: string, done: bool, reward: bool}>, percent: int, next: string, rejected: bool}
     */
    public function progress(Store $store, Collection $rewards): array
    {
        $food = $store->isRestaurant();
        $items = $food ? 'menu items' : 'products';
        $approved = (int) ($store->approved_products_count ?? 0);
        $steps = [
            ['key' => 'joined', 'label' => 'Signed up', 'emoji' => '📝', 'done' => true, 'reward' => false],
            ['key' => 'products', 'label' => self::PRODUCTS_NEEDED.' '.$items, 'emoji' => $food ? '🍽️' : '🛍️', 'done' => $approved >= self::PRODUCTS_NEEDED, 'reward' => true],
            ['key' => 'kyc', 'label' => 'KYC approved', 'emoji' => '🪪', 'done' => $store->provider?->kyc_status === 'approved', 'reward' => false],
            ['key' => 'live', 'label' => $food ? 'Restaurant live' : 'Shop live', 'emoji' => '✅', 'done' => $store->approved_at !== null, 'reward' => true],
            ['key' => 'first_order', 'label' => 'First order', 'emoji' => '🎉', 'done' => $rewards->has(FieldAssociateReward::FIRST_ORDER), 'reward' => true],
        ];

        $done = count(array_filter($steps, fn ($s) => $s['done']));
        $rejected = $store->approval_status === 'rejected';
        $next = match (true) {
            $rejected => 'Not approved: '.($store->approval_note ?: 'ask the office what to fix'),
            ! $steps[1]['done'] => 'Next: add '.self::PRODUCTS_NEEDED.' '.$items.' ('.min($approved, self::PRODUCTS_NEEDED).' approved so far)',
            ! $steps[2]['done'] => 'Next: the owner uploads their KYC, then the office approves it',
            ! $steps[3]['done'] => ($food ? 'Next: restaurant' : 'Next: shop').' approval by the office',
            ! $steps[4]['done'] => 'Next: first order from a customer (its reward comes '.self::holdDays().' days after the order)',
            default => 'All done 🎉',
        };

        return ['steps' => $steps, 'percent' => (int) round($done / count($steps) * 100), 'next' => $next, 'rejected' => $rejected];
    }

    /** @return array<string, int|float> see months() */
    public function month(FieldAssociate $associate, Carbon $month): array
    {
        return $this->months(collect([$associate]), $month)[$associate->id];
    }

    /**
     * Each associate's month: shops and restaurants signed up and live, 10-products and first orders, the bonus, and the
     * total to pay.
     *
     * @param  Collection<int, FieldAssociate>  $associates
     * @return array<int, array{signed_up: int, signed_up_shops: int, signed_up_restaurants: int, live: int, live_shops: int, live_restaurants: int, live_amount: float, products: int, products_amount: float, first_orders: int, first_order_amount: float, complete: int, bonus: float, bonus_level: int, earned: float}>
     */
    public function months(Collection $associates, Carbon $month): array
    {
        [$from, $to] = [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];
        $ids = $associates->pluck('id');
        $food = \App\Support\Modules::FOOD;

        $signedUp = Store::withTrashed()->whereIn('field_associate_id', $ids)->whereBetween('created_at', [$from, $to])
            ->selectRaw('field_associate_id, module, COUNT(*) as n')->groupBy('field_associate_id', 'module')->get()->groupBy('field_associate_id');
        $rewards = FieldAssociateReward::query()->join('stores', 'stores.id', '=', 'field_associate_rewards.store_id')
            ->whereIn('field_associate_rewards.field_associate_id', $ids)->whereBetween('field_associate_rewards.earned_at', [$from, $to])
            ->selectRaw('field_associate_rewards.field_associate_id as aid, field_associate_rewards.kind, stores.module, COUNT(*) as n, SUM(field_associate_rewards.amount) as total')
            ->groupBy('field_associate_rewards.field_associate_id', 'field_associate_rewards.kind', 'stores.module')->get()->groupBy('aid');
        $bonuses = FieldAssociateBonus::query()->whereIn('field_associate_id', $ids)->whereDate('month', $from->toDateString())->get()->keyBy('field_associate_id');
        $complete = $this->completeShops($ids, $from);

        $out = [];
        foreach ($ids as $id) {
            $mine = $rewards[$id] ?? collect();
            $kind = fn (string $k) => $mine->where('kind', $k);
            $joined = $signedUp[$id] ?? collect();
            $live = $kind(FieldAssociateReward::SHOP_LIVE);
            $bonus = $bonuses[$id] ?? null;
            $row = [
                'signed_up' => (int) $joined->sum('n'),
                'signed_up_shops' => (int) $joined->where('module', '!=', $food)->sum('n'),
                'signed_up_restaurants' => (int) $joined->where('module', $food)->sum('n'),
                'live' => (int) $live->sum('n'),
                'live_shops' => (int) $live->where('module', '!=', $food)->sum('n'),
                'live_restaurants' => (int) $live->where('module', $food)->sum('n'),
                'live_amount' => round((float) $live->sum('total'), 2),
                'products' => (int) $kind(FieldAssociateReward::PRODUCTS)->sum('n'),
                'products_amount' => round((float) $kind(FieldAssociateReward::PRODUCTS)->sum('total'), 2),
                'first_orders' => (int) $kind(FieldAssociateReward::FIRST_ORDER)->sum('n'),
                'first_order_amount' => round((float) $kind(FieldAssociateReward::FIRST_ORDER)->sum('total'), 2),
                'complete' => (int) ($complete[$id] ?? 0),
                'bonus' => round((float) ($bonus->amount ?? 0), 2),
                'bonus_level' => (int) ($bonus->level_shops ?? 0),
            ];
            $row['earned'] = round($row['live_amount'] + $row['products_amount'] + $row['first_order_amount'] + $row['bonus'], 2);
            $out[$id] = $row;
        }

        return $out;
    }

    /**
     * The bar on top: progress to the next bonus level (or the old monthly target when no level is set).
     *
     * @return array{goal: int, done: int, percent: int, text: string, reward: float}|null
     */
    public static function goal(int $complete): ?array
    {
        $levels = self::bonusLevels();
        if ($levels === []) {
            $target = self::monthlyTarget();

            return $target > 0 ? ['goal' => $target, 'done' => $complete, 'percent' => min(100, (int) round($complete / $target * 100)), 'text' => "{$complete} of {$target} done", 'reward' => 0.0] : null;
        }
        $next = collect($levels)->first(fn ($l) => $complete < $l[0]);
        if ($next === null) {
            $top = end($levels);

            return ['goal' => $top[0], 'done' => $complete, 'percent' => 100, 'text' => "{$complete} shops 🎉 Top bonus reached!", 'reward' => $top[1]];
        }

        return ['goal' => $next[0], 'done' => $complete, 'percent' => min(100, (int) round($complete / $next[0] * 100)),
            'text' => "{$complete} of {$next[0]} shops for the ".self::money($next[1]).' bonus', 'reward' => $next[1]];
    }

    /**
     * An associate's shops with everything the progress bars need, newest first.
     *
     * @return Collection<int, array{store: Store, progress: array, rewards: Collection, history: Collection}>
     */
    public function shops(FieldAssociate $associate, int $limit = 200, bool $withHistory = false): Collection
    {
        $stores = $associate->stores()->with(['provider:id,user_id,kyc_status', 'fieldAssociate:id,user_id'])
            ->withCount(['products as approved_products_count' => fn ($q) => $q->where('is_approved', true)->where('is_active', true)])
            ->latest('id')->limit($limit)->get();
        $this->sync($stores);
        $rewards = FieldAssociateReward::query()->whereIn('store_id', $stores->pluck('id'))->get()->groupBy('store_id');
        $history = $withHistory
            ? \App\Models\FieldAssociateCreditChange::query()->with(['from:id,code', 'to:id,code', 'changedBy:id,name'])->whereIn('store_id', $stores->pluck('id'))->latest('id')->get()->groupBy('store_id')
            : collect();

        return $stores->map(function (Store $store) use ($rewards, $history) {
            $mine = ($rewards[$store->id] ?? collect())->keyBy('kind');

            return ['store' => $store, 'progress' => $this->progress($store, $mine), 'rewards' => $mine, 'history' => $history[$store->id] ?? collect()];
        });
    }

    /** "₹1,500" (whole rupees shown without paise). */
    public static function money(float $amount): string
    {
        $symbol = Setting::get('locale.currency_symbol', '₹');

        return $symbol.(fmod($amount, 1.0) === 0.0 ? number_format($amount) : number_format($amount, 2));
    }
}
