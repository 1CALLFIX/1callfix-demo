<?php

namespace App\Livewire\Franchises;

use App\Models\Booking;
use App\Models\Franchise;
use App\Services\AuthorizationService;
use App\Services\TimezoneResolver;
use App\Support\InfoPages\FranchisePage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Franchise Insights (owner 2026-11-17): a franchise owner's live view of their city, read straight from real bookings
 * (no estimates, no sample data). Read-only. Same people as Franchise Earnings: Super Admin sees every franchise, an owner
 * their own, plus anyone holding commissions.view for it. Super Admin switches the blocks on or off on
 * Admin → Franchise page (insights_* switches); Super Admin always sees every block.
 *
 * Counts cover home-service bookings, the module that carries franchise_id today; other modules join as they do.
 * A "booking" is a real one (Booking::booked(): an unpaid online attempt is not a booking).
 */
class Insights extends Component
{
    public ?int $franchiseId = null;

    /** Y-m in the platform timezone */
    public string $month = '';

    protected $queryString = ['franchiseId', 'month'];

    public function mount(): void
    {
        abort_if($this->franchises()->isEmpty(), 403, 'You do not have a franchise to show insights for.');

        if (! $this->franchiseId || ! $this->franchises()->contains('id', $this->franchiseId)) {
            $this->franchiseId = $this->franchises()->first()->id;
        }
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now(app(TimezoneResolver::class)->platformTimezone())->format('Y-m');
        }
    }

    /** @return Collection<int, Franchise> */
    private function franchises(): Collection
    {
        $user = auth()->user();
        $all = Franchise::orderBy('name')->get(['id', 'name', 'owner_user_id', 'city_id', 'country_id']);
        if ($user->role === 'super_admin') {
            return $all;
        }
        $authz = app(AuthorizationService::class);

        return $all->filter(fn (Franchise $f) => $f->owner_user_id === $user->id
            || $authz->can($user, 'commissions.view', array_filter(['franchise_id' => $f->id, 'city_id' => $f->city_id, 'country_id' => $f->country_id])))->values();
    }

    /** @return array{placed:int, completed:int, cancelled:int, rate:?float, avg:float, customers:int, new:int, repeat:int, partners:int} */
    private function figures(int $franchiseId, Carbon $start, Carbon $end): array
    {
        $month = Booking::booked()->where('franchise_id', $franchiseId)->whereBetween('created_at', [$start, $end]);
        $placed = (clone $month)->count();
        $cancelled = (clone $month)->where('status', 'cancelled')->count();
        $done = Booking::where('franchise_id', $franchiseId)->where('status', 'completed')->whereBetween('completed_at', [$start, $end]);
        $completed = (clone $done)->count();
        $value = (float) (clone $done)->sum(DB::raw('COALESCE(price_final, price_quoted)'));

        $customerIds = (clone $month)->distinct()->pluck('customer_id');
        $returning = $customerIds->isEmpty() ? 0 : Booking::booked()->where('franchise_id', $franchiseId)
            ->whereIn('customer_id', $customerIds)->where('created_at', '<', $start)->distinct()->count('customer_id');

        return [
            'placed' => $placed,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'rate' => $placed > 0 ? round(100 * $completed / $placed, 1) : null,
            'avg' => $completed > 0 ? round($value / $completed, 2) : 0.0,
            'customers' => $customerIds->count(),
            'new' => $customerIds->count() - $returning,
            'repeat' => $returning,
            'partners' => (clone $done)->whereNotNull('provider_id')->distinct()->count('provider_id'),
        ];
    }

    public function render()
    {
        $franchise = $this->franchises()->firstWhere('id', $this->franchiseId);
        abort_unless($franchise, 404);

        $tz = app(TimezoneResolver::class)->platformTimezone();
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $this->month.'-01 00:00:00', $tz)->utc();
        $end = $start->copy()->setTimezone($tz)->addMonth()->utc();
        $prevStart = $start->copy()->setTimezone($tz)->subMonth()->utc();

        $now = $this->figures($franchise->id, $start, $end);
        $before = $this->figures($franchise->id, $prevStart, $start);

        $topServices = Booking::booked()->where('franchise_id', $franchise->id)->whereBetween('created_at', [$start, $end])
            ->select('service_id', DB::raw('COUNT(*) as n'))->groupBy('service_id')->orderByDesc('n')->limit(5)->get();
        $names = \App\Models\Service::whereIn('id', $topServices->pluck('service_id'))->pluck('name', 'id');

        // The last 14 days, one bar a day, in the platform timezone.
        $from = now($tz)->startOfDay()->subDays(13);
        $perDay = Booking::booked()->where('franchise_id', $franchise->id)->where('created_at', '>=', $from->copy()->utc())->get(['created_at'])
            ->countBy(fn ($b) => $b->created_at->setTimezone($tz)->format('Y-m-d'));
        $trend = collect(range(0, 13))->map(fn ($i) => ['day' => $from->copy()->addDays($i), 'n' => (int) ($perDay[$from->copy()->addDays($i)->format('Y-m-d')] ?? 0)]);

        $isSuper = auth()->user()->role === 'super_admin';
        $show = fn (string $k) => $isSuper || (bool) FranchisePage::get($k);

        return view('livewire.franchises.insights', [
            'franchises' => $this->franchises(),
            'franchise' => $franchise,
            'now' => $now,
            'before' => $before,
            'top' => $topServices->map(fn ($r) => ['name' => $names[$r->service_id] ?? 'Service', 'n' => (int) $r->n]),
            'trend' => $trend,
            'trendMax' => max(1, (int) $trend->max('n')),
            'showFunnel' => $show('insights_funnel'),
            'showCustomers' => $show('insights_customers'),
            'showServices' => $show('insights_services'),
            'months' => collect(range(0, 11))->map(fn ($i) => now($tz)->startOfMonth()->subMonths($i)->format('Y-m')),
        ])->layout('layouts.admin', ['title' => 'Franchise Insights']);
    }
}
