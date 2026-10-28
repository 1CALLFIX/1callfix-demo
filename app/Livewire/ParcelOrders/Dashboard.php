<?php

namespace App\Livewire\ParcelOrders;

use App\Models\Commission;
use App\Models\FieldWorker;
use App\Models\FieldWorkerCashDue;
use App\Models\Franchise;
use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Services\AuthorizationService;
use App\Services\TimezoneResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Parcel Stage 1 (2026-10-25): the screen a franchise owner checks every morning. Read-only, one franchise or
 * all the user can see, built on the same row scope as Parcel Orders (`parcel_orders.view` through
 * AuthorizationService::scopeQuery), so a franchise or zone admin only ever counts their own area:
 *  - today's orders by outcome, and the parcels still waiting for a rider (with their age);
 *  - average time from booking (or release, for a scheduled parcel) to a rider accepting;
 *  - upcoming scheduled pickups;
 *  - riders: onboarded, online with a fresh location, on a job;
 *  - rider cash dues not yet recovered;
 *  - the franchise's parcel earnings (commissions.franchise_commission) today and this month — shown only to
 *    Super Admin, the franchise owner, or a holder of commissions.view, like Franchise Earnings.
 */
class Dashboard extends Component
{
    public ?int $franchiseId = null;

    protected $queryString = ['franchiseId'];

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('parcel_orders.view'), 403, 'You do not have permission to view Parcel orders.');

        $franchises = $this->franchises();
        if ($this->franchiseId && ! $franchises->contains('id', $this->franchiseId)) {
            $this->franchiseId = null;
        }
        if (! $this->franchiseId && $franchises->count() === 1) {
            $this->franchiseId = $franchises->first()->id;
        }
    }

    /** A franchise outside the user's scope is never kept (it could not widen the row scope anyway). */
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
            ->scopeQuery(Franchise::query(), auth()->user(), 'parcel_orders.view', [
                'franchise_id' => 'id', 'city_id' => 'city_id', 'country_id' => 'country_id', 'zone_id' => 'zones.id',
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'owner_user_id']);
    }

    private function orders()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(ParcelOrder::query(), auth()->user(), 'parcel_orders.view', [
                'zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id',
            ])
            ->when($this->franchiseId, fn ($q) => $q->where('franchise_id', $this->franchiseId));
    }

    private function riders()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(FieldWorker::query(), auth()->user(), 'parcel_orders.view', [
                'zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id',
            ])
            ->whereHas('capabilities', fn ($q) => $q->where('capability_type', 'parcel_rider'))
            ->when($this->franchiseId, fn ($q) => $q->where('franchise_id', $this->franchiseId));
    }

    private function seesEarnings(): bool
    {
        $user = auth()->user();
        if ($user->role === 'super_admin') {
            return true;
        }
        // Anyone else sees one franchise's earnings at a time: their own, or one their commissions.view covers.
        $franchise = $this->franchiseId ? Franchise::find($this->franchiseId) : null;

        return $franchise && ($franchise->owner_user_id === $user->id || app(AuthorizationService::class)->can($user, 'commissions.view',
            array_filter(['franchise_id' => $franchise->id, 'city_id' => $franchise->city_id, 'country_id' => $franchise->country_id])));
    }

    public function render()
    {
        $tz = app(TimezoneResolver::class)->platformTimezone();
        $todayStart = now($tz)->startOfDay()->utc();
        $monthStart = now($tz)->startOfMonth()->utc();

        $today = (clone $this->orders())->where('created_at', '>=', $todayStart)
            ->select('status', DB::raw('COUNT(*) as n'))->groupBy('status')->pluck('n', 'status');
        $inProgress = ['assigned', 'worker_en_route_pickup', 'picked_up', 'en_route_dropoff'];

        // Searching for a rider = released to riders (or never held) and not yet accepted.
        $waiting = (clone $this->orders())->where('status', 'searching_worker')
            ->with('zone:id,name')->orderBy('created_at')->limit(10)
            ->get(['id', 'code', 'zone_id', 'created_at', 'dispatch_released_at', 'payment_method']);
        $waitingCount = (clone $this->orders())->where('status', 'searching_worker')->count();

        // Time to assign, for parcels a rider accepted today: from release (or booking) to the 'assigned' history row.
        $assignedToday = (clone $this->orders())
            ->whereHas('statusHistory', fn ($q) => $q->where('status', 'assigned')->where('changed_at', '>=', $todayStart))
            ->with(['statusHistory' => fn ($q) => $q->where('status', 'assigned')->orderBy('changed_at')])
            ->get(['id', 'created_at', 'dispatch_released_at']);
        $assignSeconds = $assignedToday->map(function (ParcelOrder $o) {
            $at = $o->statusHistory->first()?->changed_at;
            $from = $o->dispatch_released_at ?? $o->created_at;

            return $at ? max(0, Carbon::parse($from)->diffInSeconds(Carbon::parse($at), false)) : null;
        })->filter(fn ($s) => $s !== null);

        $scheduled = (clone $this->orders())->where('status', 'pending')->where('delivery_type', 'scheduled')
            ->where('scheduled_at', '>=', now())->count();

        $staleAfter = max(1, (int) Setting::get('provider.location_stale_after_minutes', '30'));
        $busyIds = (clone $this->orders())->whereIn('status', ParcelOrder::ACTIVE_RIDER_STATUSES)->whereNotNull('assigned_worker_id')->pluck('assigned_worker_id');

        $orderIds = (clone $this->orders())->select('id');
        $duesOutstanding = round((float) FieldWorkerCashDue::outstanding()->whereIn('parcel_order_id', $orderIds)
            ->sum(DB::raw('amount_owed - amount_settled')), 2);

        $seesEarnings = $this->seesEarnings();
        $earnings = null;
        if ($seesEarnings) {
            $commissions = Commission::whereIn('parcel_order_id', (clone $this->orders())->select('id'));
            $earnings = [
                'today' => round((float) (clone $commissions)->where('created_at', '>=', $todayStart)->sum('franchise_commission'), 2),
                'month' => round((float) (clone $commissions)->where('created_at', '>=', $monthStart)->sum('franchise_commission'), 2),
                'month_orders' => (clone $commissions)->where('created_at', '>=', $monthStart)->count(),
            ];
        }

        return view('livewire.parcel-orders.dashboard', [
            'franchises' => $this->franchises(),
            'todayTotal' => (int) $today->sum(),
            'todayDelivered' => (int) ($today['delivered'] ?? 0),
            'todayCancelled' => (int) ($today['cancelled'] ?? 0),
            'todayInProgress' => (int) collect($inProgress)->sum(fn ($s) => $today[$s] ?? 0),
            'waiting' => $waiting,
            'waitingCount' => $waitingCount,
            'avgAssignMinutes' => $assignSeconds->isEmpty() ? null : round($assignSeconds->avg() / 60, 1),
            'assignedTodayCount' => $assignSeconds->count(),
            'scheduledUpcoming' => $scheduled,
            'ridersTotal' => (clone $this->riders())->where('is_active', true)->count(),
            'ridersOnline' => (clone $this->riders())->where('is_active', true)->where('is_online', true)
                ->where('location_updated_at', '>=', now()->subMinutes($staleAfter))->count(),
            'ridersOnJob' => (clone $this->riders())->whereIn('id', $busyIds)->count(),
            'duesOutstanding' => $duesOutstanding,
            'earnings' => $earnings,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
            'tz' => $tz,
        ])->layout('layouts.admin', ['title' => 'Parcel Dashboard']);
    }
}
