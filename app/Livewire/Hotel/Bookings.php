<?php

namespace App\Livewire\Hotel;

use App\Livewire\Hotel\Concerns\InteractsWithHotel;
use App\Models\HotelReservation;
use App\Services\Hotels\HotelStayService;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Hotel portal → Bookings (Hotels H3). Tabs with counts: To accept (paid, waiting for this hotel, with the time left),
 * Arriving (confirmed: type the guest's 4-digit code to check in, or mark "did not arrive" from check-in day),
 * Staying (check out), Past. Unpaid bookings never show: no payment, no booking. Every action goes through
 * HotelStayService, so the rules (codes, charges, refunds) are the same everywhere.
 */
class Bookings extends Component
{
    use InteractsWithHotel;

    public const TABS = ['accept' => 'To accept', 'arriving' => 'Arriving', 'staying' => 'Staying', 'past' => 'Past'];

    #[Url(as: 'tab')]
    public string $tab = 'arriving';

    /** @var array<int, string> check-in code typed per booking */
    public array $codes = [];

    public string $declineReason = '';

    public string $notice = '';

    public string $error = '';

    private function own(int $id): HotelReservation
    {
        return HotelReservation::where('accommodation_id', $this->hotel()->id)->with('franchise', 'accommodation')->findOrFail($id);
    }

    private function run(callable $fn, string $done): void
    {
        $this->reset('notice', 'error');
        try {
            $fn();
            $this->notice = $done;
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function accept(int $id, HotelStayService $stays): void
    {
        $r = $this->own($id); // another hotel's booking: 404, never an action
        $this->run(fn () => $stays->accept($r), 'Accepted. The guest has their check-in code.');
    }

    public function decline(int $id, HotelStayService $stays): void
    {
        $r = $this->own($id);
        $this->validate(['declineReason' => ['required', 'string', 'min:3', 'max:200']], ['declineReason.required' => 'Tell the guest why (full, repairs…).']);
        $this->run(fn () => $stays->decline($r, trim($this->declineReason)), 'Declined. The guest gets a full refund.');
        $this->reset('declineReason');
    }

    public function checkIn(int $id, HotelStayService $stays): void
    {
        $r = $this->own($id);
        $this->run(fn () => $stays->checkIn($r, (string) ($this->codes[$id] ?? '')), 'Checked in.');
        unset($this->codes[$id]);
    }

    public function checkOut(int $id, HotelStayService $stays): void
    {
        $r = $this->own($id); // another hotel's booking: 404, never an action
        $this->run(fn () => $stays->checkOut($r), 'Checked out. You are paid after the hold time.');
    }

    public function noShow(int $id, HotelStayService $stays): void
    {
        $r = $this->own($id); // another hotel's booking: 404, never an action
        $this->run(fn () => $stays->noShow($r), 'Marked as not arrived. The no-show charge is kept; the rest is refunded.');
    }

    public function render()
    {
        $hotel = $this->hotel();
        $base = fn () => HotelReservation::where('accommodation_id', $hotel->id)->where('payment_status', '!=', 'pending');
        $queries = [
            'accept' => fn () => $base()->where('status', 'pending')->orderBy('accept_by'),
            'arriving' => fn () => $base()->where('status', 'confirmed')->orderBy('check_in_date'),
            'staying' => fn () => $base()->where('status', 'checked_in')->orderBy('check_out_date'),
            'past' => fn () => $base()->whereIn('status', ['checked_out', 'completed', 'cancelled'])->latest('updated_at'),
        ];
        $tab = array_key_exists($this->tab, $queries) ? $this->tab : 'arriving';

        return view('livewire.hotel.bookings', [
            'tabs' => self::TABS,
            'active' => $tab,
            'counts' => collect($queries)->except('past')->map(fn ($q) => $q()->count())->all(),
            'rows' => $queries[$tab]()->with(['rooms.roomType', 'rooms.ratePlan', 'guests', 'franchise'])->limit(100)->get(),
            'today' => \Illuminate\Support\Carbon::parse(app(\App\Services\TimezoneResolver::class)->format(now(), $hotel->franchise, 'Y-m-d')),
        ])->layout('components.layouts.hotel', ['title' => 'Bookings']);
    }
}
