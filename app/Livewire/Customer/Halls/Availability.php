<?php

namespace App\Livewire\Customer\Halls;

use App\Models\Hall;
use App\Services\Halls\HallBookingService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Function halls (owner 2026-11-14): the hall page's month calendar — free, some slots free, booked, not open. Loaded
 * on its own request (lazy), so the cached hall page always shows today's bookings. A free day opens the booking page
 * on that date.
 */
#[Lazy]
class Availability extends Component
{
    #[Locked]
    public int $hallId = 0;

    #[Locked]
    public string $citySlug = '';

    /** First day of the month shown (Y-m-01). */
    public string $month = '';

    public function mount(int $hallId, string $citySlug): void
    {
        $this->hallId = $hallId;
        $this->citySlug = $citySlug;
        $this->month = Carbon::parse(HallBookingService::dateWindow($this->hall())[0])->startOfMonth()->toDateString();
    }

    private function hall(): Hall
    {
        $hall = Hall::with('franchise')->find($this->hallId);
        abort_unless($hall && HallBookingService::bookable($hall), 404);

        return $hall;
    }

    public function move(int $step): void
    {
        $hall = $this->hall();
        [$first, $last] = HallBookingService::dateWindow($hall);
        $next = Carbon::parse($this->month)->addMonthsNoOverflow($step > 0 ? 1 : -1)->startOfMonth();
        if ($next->gte(Carbon::parse($first)->startOfMonth()) && $next->lte(Carbon::parse($last)->startOfMonth())) {
            $this->month = $next->toDateString();
        }
    }

    public function placeholder()
    {
        return '<div class="h-72 animate-pulse rounded-2xl bg-slate-100" aria-hidden="true"></div>';
    }

    public function render()
    {
        $hall = $this->hall();
        $month = Carbon::parse($this->month)->startOfMonth();
        [$first, $last] = HallBookingService::dateWindow($hall);

        return view('livewire.customer.halls.availability', [
            'shownMonth' => $month,
            'states' => HallBookingService::availability($hall, $month->toDateString(), $month->daysInMonth),
            'lead' => ($month->dayOfWeekIso - 1),
            'canBack' => $month->gt(Carbon::parse($first)->startOfMonth()),
            'canNext' => $month->lt(Carbon::parse($last)->startOfMonth()),
            'bookUrl' => fn (string $date) => route('customer.halls.book', ['hall' => $hall->id, 'date' => $date]),
        ]);
    }
}
