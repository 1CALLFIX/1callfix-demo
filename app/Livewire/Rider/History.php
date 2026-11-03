<?php

namespace App\Livewire\Rider;

use App\Livewire\Rider\Concerns\InteractsWithRider;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceReturn;
use App\Models\MarketplaceSettlement;
use App\Models\ParcelOrder;
use App\Models\Setting;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The rider's finished jobs, newest first. Rider app 2026-11-04 (owner: one rider, every kind of job): parcels, shop
 * and food deliveries and return pickups in one list, each with its coloured label and what the rider earned.
 */
class History extends Component
{
    use InteractsWithRider;
    use WithPagination;

    /** How many of each kind are read before merging (a page shows PER_PAGE of the merged, newest-first list). */
    private const READ_EACH = 200;

    private const PER_PAGE = 15;

    public function render()
    {
        $rider = $this->rider();

        $parcels = ParcelOrder::where('assigned_worker_id', $rider->id)->whereIn('status', ['delivered', 'cancelled'])
            ->with(['dropoffAddress:id,address_line', 'commission', 'franchise'])->latest('id')->limit(self::READ_EACH)->get()
            ->map(fn (ParcelOrder $o) => [
                'kind' => 'parcel', 'code' => $o->code, 'done' => $o->status === 'delivered',
                'where' => $o->dropoffAddress?->address_line, 'at' => $o->delivered_at ?? $o->updated_at, 'franchise' => $o->franchise,
                'cash' => $o->payment_method === 'cash', 'amount' => (float) ($o->price_final ?? $o->price_quoted),
                'earned' => $o->commission ? (float) $o->commission->provider_commission : null,
            ]);

        $orders = MarketplaceOrder::where('assigned_worker_id', $rider->id)->where('order_type', 'delivery')->whereIn('status', ['completed', 'cancelled'])
            ->with(['store:id,name', 'franchise'])->latest('id')->limit(self::READ_EACH)->get();
        $shares = MarketplaceSettlement::whereIn('marketplace_order_id', $orders->pluck('id'))->where('field_worker_id', $rider->id)
            ->get(['marketplace_order_id', 'rider_share', 'rider_topup'])->keyBy('marketplace_order_id');
        $shopJobs = $orders->map(fn (MarketplaceOrder $o) => [
            'kind' => $o->isFood() ? 'food' : 'shop', 'code' => $o->code, 'done' => $o->status === 'completed',
            'where' => $o->store?->name, 'at' => $o->completed_at ?? $o->updated_at, 'franchise' => $o->franchise,
            'cash' => $o->payment_method === 'cash', 'amount' => (float) $o->total_amount,
            'earned' => isset($shares[$o->id]) ? round((float) $shares[$o->id]->rider_share + (float) $shares[$o->id]->rider_topup, 2) : null,
        ]);

        $returns = MarketplaceReturn::where('pickup_worker_id', $rider->id)->where('pickup_status', MarketplaceReturn::PICKUP_DROPPED)
            ->with(['order:id,code', 'store:id,name', 'franchise'])->latest('id')->limit(self::READ_EACH)->get()
            ->map(fn (MarketplaceReturn $r) => [
                'kind' => 'return', 'code' => $r->order?->code, 'done' => true,
                'where' => 'Back to '.$r->store?->name, 'at' => $r->dropped_at ?? $r->updated_at, 'franchise' => $r->franchise,
                'cash' => false, 'amount' => null, 'earned' => (float) $r->pickup_fee,
            ]);

        $all = $parcels->concat($shopJobs)->concat($returns)->sortByDesc(fn ($row) => $row['at']?->timestamp ?? 0)->values();
        $page = max(1, (int) $this->getPage());
        $paged = new LengthAwarePaginator($all->forPage($page, self::PER_PAGE)->values(), $all->count(), self::PER_PAGE, $page,
            ['path' => route('rider.history'), 'pageName' => 'page']);

        return view('livewire.rider.history', [
            'jobs' => $paged,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.rider', ['title' => 'History']);
    }
}
