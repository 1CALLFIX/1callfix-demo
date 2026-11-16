<?php

namespace App\Livewire\Customer\Orders;

use App\Models\Booking;
use App\Models\EventOrder;
use App\Models\HallBooking;
use App\Models\HotelReservation;
use App\Models\HouseVisit;
use App\Models\MarketplaceOrder;
use App\Models\ParcelOrder;
use App\Models\ParcelPackPurchase;
use App\Models\Setting;
use App\Services\TimezoneResolver;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "My Orders" (owner 2026-11-16, the Amazon / Flipkart pattern): ONE list of everything the signed-in customer
 * has ordered or booked, from every module, whether or not that module is switched on today. Each row opens
 * that order's own page. Only the customer's own rows are ever read (customer_id = the signed-in user), and
 * an online order whose payment never arrived is not an order (NO PAYMENT, NO BOOKING), same as every list.
 *
 * `kind` narrows to one module; `filter` narrows by progress ('' all, active, completed, cancelled).
 * Statuses are each module's real status string, grouped into those three buckets only for the filter.
 */
class Index extends Component
{
    use WithPagination;

    /** '' = all; otherwise active | completed | cancelled. */
    public string $filter = '';

    /** '' = every module; otherwise service | parcel | pack | shop | food | grocery | pharmacy | hotel | hall | event | visit. */
    public string $kind = '';

    private const PER_SOURCE = 60;

    private const PER_PAGE = 10;

    private const DONE = ['completed', 'delivered', 'checked_out', 'returned', 'fulfilled'];

    private const ENDED = ['cancelled', 'rejected', 'declined', 'expired', 'failed', 'no_show', 'refunded'];

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function updatingKind(): void
    {
        $this->resetPage();
    }

    public static function bucket(string $status): string
    {
        if (in_array($status, self::DONE, true)) {
            return 'completed';
        }

        return in_array($status, self::ENDED, true) ? 'cancelled' : 'active';
    }

    /** An online order whose payment never arrived is not an order. */
    private static function isRealOrder(object $m): bool
    {
        return ! ($m->payment_method === 'online' && $m->payment_status === 'pending' && in_array($m->status, ['pending', 'cancelled', 'expired'], true));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(): Collection
    {
        $me = auth()->id();
        $tz = app(TimezoneResolver::class);
        $rows = collect();

        Booking::where('customer_id', $me)->booked()
            ->with(['service:id,name', 'franchise:id,country_id', 'franchise.country:id,default_timezone'])
            ->latest()->limit(self::PER_SOURCE)->get()
            ->each(function (Booking $b) use ($rows, $tz) {
                $rows->push([
                    'kind' => 'service', 'at' => $b->created_at, 'status' => $b->status,
                    'title' => $b->service?->name ?? 'Service', 'code' => $b->code,
                    'line' => $b->scheduled_at ? $tz->format($b->scheduled_at, $b->franchise, 'D j M, g:i A') : 'As soon as possible',
                    'amount' => (float) ($b->price_final ?? $b->price_quoted),
                    'href' => route('customer.orders.show', $b),
                    'paid' => $b->payment_status === 'paid', 'cash' => $b->payment_method === 'cash',
                ]);
            });

        ParcelOrder::where('customer_id', $me)->booked()->latest('id')->limit(self::PER_SOURCE)->get()
            ->each(function (ParcelOrder $p) use ($rows) {
                $rows->push([
                    'kind' => 'parcel', 'at' => $p->created_at, 'status' => $p->status,
                    'title' => 'Parcel delivery', 'code' => $p->code,
                    'line' => $p->recipient_name ? 'To '.$p->recipient_name : 'Parcel',
                    'amount' => $p->orderTotalPrice(), 'href' => route('customer.parcels.show', $p),
                ]);
            });

        MarketplaceOrder::where('customer_id', $me)->booked()->with('store:id,name')->latest('id')->limit(self::PER_SOURCE)->get()
            ->each(function (MarketplaceOrder $o) use ($rows) {
                $kind = in_array($o->module, ['food', 'grocery', 'pharmacy'], true) ? $o->module : 'shop';
                $word = ucfirst($kind);
                $rows->push([
                    'kind' => $kind, 'at' => $o->created_at, 'status' => $o->status,
                    'title' => $o->store?->name ?? $word.' order', 'code' => $o->code,
                    'line' => $kind === 'food' ? 'Food delivery' : $word.' order',
                    'amount' => (float) ($o->price_final ?? $o->total_amount), 'href' => route('customer.shop.orders.show', $o),
                ]);
            });

        // Prepaid parcel packs: every pack the customer paid for, active or used up (the Packs page shows only active ones).
        ParcelPackPurchase::where('user_id', $me)->whereNotNull('paid_at')->latest('id')->limit(self::PER_SOURCE)->get()
            ->each(function (ParcelPackPurchase $p) use ($rows) {
                $rows->push([
                    'kind' => 'pack', 'at' => $p->paid_at, 'status' => $p->status,
                    'title' => $p->name ?: 'Parcel pack', 'code' => 'PACK-'.$p->id,
                    'line' => max(0, (int) $p->parcels_total - (int) $p->parcels_used).' of '.$p->parcels_total.' parcels left',
                    'amount' => (float) $p->price_paid, 'href' => route('customer.parcels.packs'),
                ]);
            });

        // Property for rent: each visit the customer asked for (no money is taken, so no amount).
        HouseVisit::where('customer_id', $me)->with('listing:id,title')->latest('id')->limit(self::PER_SOURCE)->get()
            ->each(function (HouseVisit $v) use ($rows) {
                $rows->push([
                    'kind' => 'visit', 'at' => $v->created_at, 'status' => $v->status,
                    'title' => $v->listing?->title ?? 'Property visit', 'code' => $v->code,
                    'line' => ($v->visit_date ? $v->visit_date->format('D j M Y') : 'Visit').($v->slot ? ' · '.$v->slot : ''),
                    'amount' => null, 'href' => route('customer.homes.visits'),
                ]);
            });

        HotelReservation::where('customer_id', $me)->with('accommodation:id,name')->latest('id')->limit(self::PER_SOURCE)->get()
            ->filter(fn ($h) => self::isRealOrder($h))
            ->each(function (HotelReservation $h) use ($rows) {
                $rows->push([
                    'kind' => 'hotel', 'at' => $h->created_at, 'status' => $h->status,
                    'title' => $h->accommodation?->name ?? 'Hotel stay', 'code' => $h->code,
                    'line' => $h->check_in_date ? $h->check_in_date->format('j M').' – '.$h->check_out_date?->format('j M Y') : 'Hotel stay',
                    'amount' => (float) ($h->price_final ?? $h->price_quoted), 'href' => route('customer.hotels.booking', $h->code),
                ]);
            });

        HallBooking::where('customer_id', $me)->with('hall:id,name')->latest('id')->limit(self::PER_SOURCE)->get()
            ->filter(fn ($b) => self::isRealOrder($b) && $b->status !== 'expired')
            ->each(function (HallBooking $b) use ($rows) {
                $rows->push([
                    'kind' => 'hall', 'at' => $b->created_at, 'status' => $b->status,
                    'title' => $b->hall?->name ?? 'Function hall', 'code' => $b->code,
                    'line' => $b->event_date ? \Illuminate\Support\Carbon::parse($b->event_date)->format('D j M Y') : 'Function hall',
                    'amount' => (float) $b->total, 'href' => route('customer.halls.booking', $b->code),
                ]);
            });

        EventOrder::where('customer_id', $me)->with('event:id,title')->latest('id')->limit(self::PER_SOURCE)->get()
            ->filter(fn ($e) => self::isRealOrder($e))
            ->each(function (EventOrder $e) use ($rows) {
                $rows->push([
                    'kind' => 'event', 'at' => $e->created_at, 'status' => $e->status,
                    'title' => $e->event?->title ?? 'Event tickets', 'code' => $e->code,
                    'line' => $e->ticket_count.' ticket'.($e->ticket_count == 1 ? '' : 's'),
                    'amount' => (float) $e->total, 'href' => route('customer.events.order', $e->code),
                ]);
            });

        return $rows->map(fn ($r) => $r + ['bucket' => self::bucket((string) $r['status'])])
            ->sortByDesc(fn ($r) => $r['at']?->getTimestamp() ?? 0)->values();
    }

    public function render()
    {
        $all = $this->rows();

        // Module chips: only the kinds this customer actually has, in the order modules are launched.
        $kinds = collect(['service', 'parcel', 'pack', 'shop', 'food', 'grocery', 'pharmacy', 'hotel', 'hall', 'event', 'visit'])
            ->filter(fn ($k) => $all->contains('kind', $k))->values();

        $shown = $all
            ->when($this->kind !== '', fn ($c) => $c->where('kind', $this->kind))
            ->when($this->filter !== '', fn ($c) => $c->where('bucket', $this->filter))
            ->values();

        $page = max(1, $this->getPage());
        $orders = new LengthAwarePaginator(
            $shown->forPage($page, self::PER_PAGE)->values(), $shown->count(), self::PER_PAGE, $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'],
        );

        return view('livewire.customer.orders.index', [
            'orders' => $orders,
            'kinds' => $kinds,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => 'My Orders']);
    }
}
