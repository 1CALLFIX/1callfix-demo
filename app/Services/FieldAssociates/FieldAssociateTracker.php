<?php

namespace App\Services\FieldAssociates;

use App\Models\FieldAssociate;
use App\Models\FieldAssociateReward;
use App\Models\MarketplaceOrder;
use App\Models\Setting;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Field associates (owner 2026-11-23): how far each shop an associate signed up has come, and what they earned.
 *
 * Two rewards per shop (owner's plan, amounts set by Super Admin on Admin → Field associates):
 *  - "Shop live": the shop's KYC is approved and the shop is approved (an admin can approve a shop only after its
 *    KYC, Stores\Manage::approveStore), so a shop whose KYC passed but which was never approved earns nothing.
 *  - "First order": the shop's first completed order from a real customer, never one placed by the shop's own
 *    owner or by the associate.
 *
 * A reward row is written once per shop and kind, with the amount at that moment, so changing the amount later
 * never rewrites a month that was already paid. Writing is idempotent (unique store + kind) and runs when a shop is
 * approved, when an order completes, and whenever the tracker pages open (a safety net, nothing is ever missed).
 * Payment happens outside the app (bank / UPI) from the monthly report; no money moves here.
 */
class FieldAssociateTracker
{
    public const REWARD_SHOP_LIVE = 'field_associates.reward_shop_live';

    public const REWARD_FIRST_ORDER = 'field_associates.reward_first_order';

    public const MONTHLY_TARGET = 'field_associates.monthly_target';

    public static function rewardShopLive(): float
    {
        return max(0.0, (float) Setting::get(self::REWARD_SHOP_LIVE, 0));
    }

    public static function rewardFirstOrder(): float
    {
        return max(0.0, (float) Setting::get(self::REWARD_FIRST_ORDER, 0));
    }

    /** Shops live per month the associates aim for; 0 = no target (the target bar is hidden). */
    public static function monthlyTarget(): int
    {
        return max(0, (int) Setting::get(self::MONTHLY_TARGET, 0));
    }

    public function syncStore(Store $store): void
    {
        $this->sync(collect([$store]));
    }

    public function syncAssociate(FieldAssociate $associate): void
    {
        $this->sync($associate->stores()->with(['provider:id,user_id', 'fieldAssociate:id,user_id'])->get());
    }

    /** @param  Collection<int, Store>  $stores */
    public function sync(Collection $stores): void
    {
        $stores = $stores->filter(fn (Store $s) => $s->field_associate_id !== null);
        if ($stores->isEmpty()) {
            return;
        }

        $have = FieldAssociateReward::query()->whereIn('store_id', $stores->pluck('id'))->get(['store_id', 'kind'])
            ->map(fn ($r) => $r->store_id.':'.$r->kind)->flip();
        $firstOrders = $this->firstOrderTimes($stores->reject(fn (Store $s) => $have->has($s->id.':'.FieldAssociateReward::FIRST_ORDER)));

        $now = now();
        $rows = [];
        foreach ($stores as $store) {
            if ($store->approved_at !== null && ! $have->has($store->id.':'.FieldAssociateReward::SHOP_LIVE)) {
                $rows[] = ['field_associate_id' => $store->field_associate_id, 'store_id' => $store->id, 'kind' => FieldAssociateReward::SHOP_LIVE,
                    'amount' => self::rewardShopLive(), 'earned_at' => $store->approved_at, 'created_at' => $now, 'updated_at' => $now];
            }
            if (isset($firstOrders[$store->id])) {
                $rows[] = ['field_associate_id' => $store->field_associate_id, 'store_id' => $store->id, 'kind' => FieldAssociateReward::FIRST_ORDER,
                    'amount' => self::rewardFirstOrder(), 'earned_at' => $firstOrders[$store->id], 'created_at' => $now, 'updated_at' => $now];
            }
        }

        if ($rows !== []) {
            // Two people opening the page at once write the same row: the unique key keeps exactly one.
            FieldAssociateReward::query()->insertOrIgnore($rows);
        }
    }

    /**
     * store id => when its first real order completed. Orders placed by the shop's own owner or by the associate who
     * signed it up never count.
     *
     * @param  Collection<int, Store>  $stores
     * @return array<int, Carbon>
     */
    private function firstOrderTimes(Collection $stores): array
    {
        if ($stores->isEmpty()) {
            return [];
        }

        $rows = MarketplaceOrder::query()->whereIn('store_id', $stores->pluck('id'))->where('status', 'completed')->whereNotNull('completed_at')
            ->selectRaw('store_id, customer_id, MIN(completed_at) as first_at')->groupBy('store_id', 'customer_id')->get();

        $byStore = $stores->keyBy('id');
        $first = [];
        foreach ($rows as $row) {
            $store = $byStore[$row->store_id];
            $own = array_filter([$store->provider?->user_id, $store->fieldAssociate?->user_id]);
            if (in_array((int) $row->customer_id, $own, true)) {
                continue;
            }
            $at = Carbon::parse($row->first_at);
            if (! isset($first[$row->store_id]) || $at->lt($first[$row->store_id])) {
                $first[$row->store_id] = $at;
            }
        }

        return $first;
    }

    /**
     * The five steps of one shop, for its progress bar: signed up → products → KYC → shop live (reward) → first order
     * (reward). $rewards = this shop's reward rows keyed by kind.
     *
     * @return array{steps: list<array{key: string, label: string, emoji: string, done: bool, reward: bool}>, percent: int, next: string, rejected: bool}
     */
    public function progress(Store $store, Collection $rewards): array
    {
        $food = $store->isRestaurant();
        $steps = [
            ['key' => 'joined', 'label' => 'Signed up', 'emoji' => '📝', 'done' => true, 'reward' => false],
            ['key' => 'products', 'label' => $food ? 'Menu added' : 'Products added', 'emoji' => $food ? '🍽️' : '🛍️', 'done' => (int) ($store->products_count ?? 0) > 0, 'reward' => false],
            ['key' => 'kyc', 'label' => 'KYC approved', 'emoji' => '🪪', 'done' => $store->provider?->kyc_status === 'approved', 'reward' => false],
            ['key' => 'live', 'label' => $food ? 'Restaurant live' : 'Shop live', 'emoji' => '✅', 'done' => $store->approved_at !== null, 'reward' => true],
            ['key' => 'first_order', 'label' => 'First order', 'emoji' => '🎉', 'done' => $rewards->has(FieldAssociateReward::FIRST_ORDER), 'reward' => true],
        ];

        $done = count(array_filter($steps, fn ($s) => $s['done']));
        $rejected = $store->approval_status === 'rejected';
        $next = match (true) {
            $rejected => 'Not approved: '.($store->approval_note ?: 'ask the office what to fix'),
            ! $steps[1]['done'] => $food ? 'Next: add the menu' : 'Next: add products',
            ! $steps[2]['done'] => 'Next: KYC approval by the office',
            ! $steps[3]['done'] => ($food ? 'Next: restaurant' : 'Next: shop').' approval by the office',
            ! $steps[4]['done'] => 'Next: first order from a customer',
            default => 'All done 🎉',
        };

        return ['steps' => $steps, 'percent' => (int) round($done / count($steps) * 100), 'next' => $next, 'rejected' => $rejected];
    }

    /**
     * One associate's month: shops signed up, shops live and first orders (with what they earned).
     *
     * @return array{signed_up: int, live: int, live_amount: float, first_orders: int, first_order_amount: float, earned: float}
     */
    public function month(FieldAssociate $associate, Carbon $month): array
    {
        return $this->months(collect([$associate]), $month)[$associate->id];
    }

    /**
     * The same, for many associates in three queries.
     *
     * @param  Collection<int, FieldAssociate>  $associates
     * @return array<int, array{signed_up: int, live: int, live_amount: float, first_orders: int, first_order_amount: float, earned: float}>
     */
    public function months(Collection $associates, Carbon $month): array
    {
        [$from, $to] = [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];
        $ids = $associates->pluck('id');

        $signedUp = Store::withTrashed()->whereIn('field_associate_id', $ids)->whereBetween('created_at', [$from, $to])
            ->selectRaw('field_associate_id, COUNT(*) as n')->groupBy('field_associate_id')->pluck('n', 'field_associate_id');
        $rewards = FieldAssociateReward::query()->whereIn('field_associate_id', $ids)->whereBetween('earned_at', [$from, $to])
            ->selectRaw('field_associate_id, kind, COUNT(*) as n, SUM(amount) as total')->groupBy('field_associate_id', 'kind')->get()
            ->groupBy('field_associate_id');

        $out = [];
        foreach ($ids as $id) {
            $kinds = ($rewards[$id] ?? collect())->keyBy('kind');
            $live = $kinds[FieldAssociateReward::SHOP_LIVE] ?? null;
            $first = $kinds[FieldAssociateReward::FIRST_ORDER] ?? null;
            $out[$id] = [
                'signed_up' => (int) ($signedUp[$id] ?? 0),
                'live' => (int) ($live->n ?? 0),
                'live_amount' => round((float) ($live->total ?? 0), 2),
                'first_orders' => (int) ($first->n ?? 0),
                'first_order_amount' => round((float) ($first->total ?? 0), 2),
                'earned' => round((float) ($live->total ?? 0) + (float) ($first->total ?? 0), 2),
            ];
        }

        return $out;
    }

    /**
     * An associate's shops with everything the progress bars need, newest first.
     *
     * @return Collection<int, array{store: Store, progress: array, rewards: Collection}>
     */
    public function shops(FieldAssociate $associate, int $limit = 200): Collection
    {
        $stores = $associate->stores()->with(['provider:id,user_id,kyc_status', 'fieldAssociate:id,user_id'])
            ->withCount('products')->latest('id')->limit($limit)->get();
        $this->sync($stores);
        $rewards = FieldAssociateReward::query()->whereIn('store_id', $stores->pluck('id'))->get()->groupBy('store_id');

        return $stores->map(function (Store $store) use ($rewards) {
            $mine = ($rewards[$store->id] ?? collect())->keyBy('kind');

            return ['store' => $store, 'progress' => $this->progress($store, $mine), 'rewards' => $mine];
        });
    }

    /** "₹1,500" (whole rupees shown without paise). */
    public static function money(float $amount): string
    {
        $symbol = Setting::get('locale.currency_symbol', '₹');

        return $symbol.(fmod($amount, 1.0) === 0.0 ? number_format($amount) : number_format($amount, 2));
    }
}
