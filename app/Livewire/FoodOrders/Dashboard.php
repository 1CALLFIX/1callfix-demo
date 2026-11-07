<?php

namespace App\Livewire\FoodOrders;

use App\Models\Franchise;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceReturn;
use App\Models\Setting;
use App\Models\Store;
use App\Services\AuthorizationService;
use App\Services\TimezoneResolver;
use App\Support\FoodSettings;
use App\Support\Modules;
use Livewire\Component;

/**
 * Food F5 (2026-11-03) — the screen a franchise owner checks during meal times. Read-only, one franchise or all the
 * user can see, on the same row scope as Marketplace Orders (`marketplace_orders.view`):
 *  - today's food orders by outcome, and how many the restaurant never accepted in time;
 *  - orders waiting for the restaurant to accept right now (with the deadline), and orders past their ready time;
 *  - restaurants: live, open now, busy mode, missing FSSAI;
 *  - open food problems, and this month's food refunds split restaurant / platform;
 *  - riders with strikes (damaged on the way) in the Food Settings strike period.
 */
class Dashboard extends Component
{
    public ?int $franchiseId = null;

    protected $queryString = ['franchiseId'];

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('marketplace_orders.view'), 403, 'You do not have permission to view orders.');

        $franchises = $this->franchises();
        if ($this->franchiseId && ! $franchises->contains('id', $this->franchiseId)) {
            $this->franchiseId = null;
        }
        if (! $this->franchiseId && $franchises->count() === 1) {
            $this->franchiseId = $franchises->first()->id;
        }
    }

    public function updatedFranchiseId(): void
    {
        $franchises = $this->franchises();
        if ($this->franchiseId && ! $franchises->contains('id', $this->franchiseId)) {
            $this->franchiseId = $franchises->count() === 1 ? $franchises->first()->id : null;
        }
    }

    private function franchises()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(Franchise::query(), auth()->user(), 'marketplace_orders.view', [
                'franchise_id' => 'id', 'city_id' => 'city_id', 'country_id' => 'country_id', 'zone_id' => 'zones.id',
            ])
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function scoped($query)
    {
        return app(AuthorizationService::class)
            ->scopeQuery($query, auth()->user(), 'marketplace_orders.view', [
                'zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id',
            ])
            ->when($this->franchiseId, fn ($q) => $q->where('franchise_id', $this->franchiseId));
    }

    private function orders()
    {
        // booked(): unpaid online orders are not orders (NO PAYMENT, NO BOOKING, owner 2026-11-07).
        return $this->scoped(MarketplaceOrder::query()->booked())->where('module', Modules::FOOD);
    }

    public function render()
    {
        $tz = app(TimezoneResolver::class)->platformTimezone();
        $todayStart = now($tz)->startOfDay()->utc();
        $monthStart = now($tz)->startOfMonth()->utc();

        $today = (clone $this->orders())->where('created_at', '>=', $todayStart)
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $notAccepted = (clone $this->orders())->where('created_at', '>=', $todayStart)->where('status', 'cancelled')
            ->where('cancellation_note', 'like', 'The restaurant did not accept%')->count();

        $waitingAccept = (clone $this->orders())->where('status', 'pending')->whereNotNull('accept_by')
            ->with('store:id,name')->orderBy('accept_by')->limit(10)->get(['id', 'code', 'store_id', 'created_at', 'accept_by']);
        $late = (clone $this->orders())->whereIn('status', ['accepted', 'preparing'])->where('ready_by', '<', now())
            ->with('store:id,name')->orderBy('ready_by')->limit(10)->get(['id', 'code', 'store_id', 'ready_by', 'status']);

        $restaurants = $this->scoped(Store::query())->where('module', Modules::FOOD)->sellable()->get();
        $orderIds = (clone $this->orders())->select('id');
        $problems = MarketplaceReturn::whereIn('marketplace_order_id', $orderIds);
        $refunded = (clone $problems)->where('status', MarketplaceReturn::REFUNDED)->where('refunded_at', '>=', $monthStart);

        $strikeDays = (int) FoodSettings::get('food.rider_strike_days');
        $strikes = (clone $problems)->where('fault', MarketplaceReturn::FAULT_TRANSIT)->where('status', '!=', MarketplaceReturn::DECLINED)
            ->whereNotNull('field_worker_id')->where('decided_at', '>=', now()->subDays(max(1, $strikeDays)))
            ->selectRaw('field_worker_id, COUNT(*) as n')->groupBy('field_worker_id')->orderByDesc('n')->limit(5)
            ->with('deliveryWorker.user:id,name')->get();
        // Food problem rules 2026-11-03: restaurants with the most "restaurant's fault" problems in the same period.
        $restaurantFaults = (clone $problems)->where('fault', MarketplaceReturn::FAULT_RESTAURANT)->where('status', '!=', MarketplaceReturn::DECLINED)
            ->where('decided_at', '>=', now()->subDays(max(1, $strikeDays)))
            ->selectRaw('store_id, COUNT(*) as n')->groupBy('store_id')->orderByDesc('n')->limit(5)
            ->with('store:id,name')->get();

        return view('livewire.food-orders.dashboard', [
            'franchises' => $this->franchises(),
            'todayTotal' => (int) $today->sum(),
            'todayDelivered' => (int) ($today['completed'] ?? 0),
            'todayCancelled' => (int) ($today['cancelled'] ?? 0),
            'todayOpen' => (int) collect(['pending', 'accepted', 'preparing', 'ready'])->sum(fn ($s) => $today[$s] ?? 0),
            'notAccepted' => $notAccepted,
            'waitingAccept' => $waitingAccept,
            'late' => $late,
            'restaurantsLive' => $restaurants->count(),
            'restaurantsOpen' => $restaurants->filter(fn (Store $s) => $s->isOpenNow())->count(),
            'restaurantsBusy' => $restaurants->filter(fn (Store $s) => $s->isBusy())->count(),
            'restaurantsNoFssai' => $restaurants->filter(fn (Store $s) => $s->missingFssai())->count(),
            'problemsOpen' => (clone $problems)->whereIn('status', MarketplaceReturn::OPEN)->count(),
            'refundMonth' => [
                'count' => (clone $refunded)->count(),
                'restaurant' => round((float) (clone $refunded)->sum('restaurant_paid'), 2),
                'platform' => round((float) (clone $refunded)->sum('platform_paid'), 2),
            ],
            'strikes' => $strikes,
            'restaurantFaults' => $restaurantFaults,
            'strikeDays' => $strikeDays,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
            'tz' => $tz,
        ])->layout('layouts.admin', ['title' => 'Food Dashboard']);
    }
}
