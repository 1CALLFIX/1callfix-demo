<?php

namespace App\Services\Visitors;

use App\Models\PageView;
use App\Models\User;
use App\Models\VisitorPresence;
use App\Services\AuthorizationService;
use App\Support\ModuleSales;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The numbers on Admin → Live Visitors (owner 2026-11-21), each row-scoped under `visitors.view` the same way as the
 * Dashboard: a franchise team sees visitors in its own city / area only; Super Admin and global holders see all.
 * Orders in the funnel come from App\Support\ModuleSales (same numbers and permissions as the Dashboard).
 */
class VisitorInsights
{
    private const SCOPE_COLUMNS = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'city_id', 'country_id' => 'country_id'];

    public function __construct(private User $viewer) {}

    private function views(CarbonInterface $from): Builder
    {
        return app(AuthorizationService::class)
            ->scopeQuery(PageView::query(), $this->viewer, 'visitors.view', self::SCOPE_COLUMNS)
            ->where('created_at', '>=', $from);
    }

    private function presence(): Builder
    {
        return app(AuthorizationService::class)
            ->scopeQuery(VisitorPresence::query(), $this->viewer, 'visitors.view', self::SCOPE_COLUMNS);
    }

    /** @return array{total: int, app: int, web: int, signed_in: int, booking: int, people: Collection} */
    public function onlineNow(int $limit = 40): array
    {
        $online = fn () => $this->presence()->where('last_seen_at', '>=', now()->subMinutes(max(1, (int) config('visitors.online_minutes', 3))));
        $byChannel = $online()->selectRaw('channel, COUNT(*) as n')->groupBy('channel')->pluck('n', 'channel');

        return [
            'total' => (int) $byChannel->sum(),
            'app' => (int) ($byChannel['app'] ?? 0),
            'web' => (int) ($byChannel['web'] ?? 0),
            'signed_in' => $online()->whereNotNull('user_id')->count(),
            'booking' => $online()->whereIn('page_type', PageClassifier::BOOKING_TYPES)->count(),
            'people' => $online()->with(['user:id,name,phone', 'city:id,name'])->orderByDesc('last_seen_at')->limit($limit)->get(),
        ];
    }

    /** @return array{visitors: int, views: int, app: int, web: int, started: int, orders: ?int, signed_in: int} */
    public function totals(CarbonInterface $from, array $moduleSales): array
    {
        $visitorsBy = $this->views($from)->selectRaw('channel, COUNT(DISTINCT visitor_id) as n')->groupBy('channel')->pluck('n', 'channel');

        return [
            'visitors' => (int) $this->views($from)->distinct()->count('visitor_id'),
            'app' => (int) ($visitorsBy['app'] ?? 0),
            'web' => (int) ($visitorsBy['web'] ?? 0),
            'views' => $this->views($from)->count(),
            'signed_in' => (int) $this->views($from)->whereNotNull('user_id')->distinct()->count('user_id'),
            'started' => (int) $this->views($from)->whereIn('page_type', PageClassifier::BOOKING_TYPES)->distinct()->count('visitor_id'),
            'orders' => $moduleSales === [] ? null : (int) collect($moduleSales)->sum('orders'),
        ];
    }

    /** Most viewed items (service, shop, restaurant, hotel, ...) in the period. */
    public function topItems(CarbonInterface $from, int $limit = 10): Collection
    {
        return $this->views($from)->where('page_type', 'item')
            ->selectRaw('path, module, MAX(title) as title, COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors')
            ->groupBy('path', 'module')->orderByDesc('views')->limit($limit)->get();
    }

    /** Where visitors came from (other websites and apps), period. */
    public function sources(CarbonInterface $from, int $limit = 6): Collection
    {
        return $this->views($from)->whereNotNull('referrer_host')
            ->selectRaw('referrer_host, COUNT(DISTINCT visitor_id) as visitors')
            ->groupBy('referrer_host')->orderByDesc('visitors')->limit($limit)->get();
    }

    /**
     * Per module: came to it → looked at an item → started to book → orders placed (ModuleSales, null when the viewer
     * cannot see that module's orders). Parcel has no item pages, so its "looked" step is its landing page.
     *
     * @return array<string, array{label: string, visited: int, viewed: int, started: int, orders: ?int}>
     */
    public function funnel(CarbonInterface $from, array $moduleSales, array $modules): array
    {
        $rows = $this->views($from)->whereNotNull('module')
            ->selectRaw('module, page_type, COUNT(DISTINCT visitor_id) as n')->groupBy('module', 'page_type')->get();
        $visited = $this->views($from)->whereNotNull('module')
            ->selectRaw('module, COUNT(DISTINCT visitor_id) as n')->groupBy('module')->pluck('n', 'module');

        $out = [];
        foreach ($modules as $key) {
            $n = fn (string $type) => (int) ($rows->first(fn ($r) => $r->module === $key && $r->page_type === $type)?->n ?? 0);
            $row = [
                'label' => PageClassifier::MODULE_LABELS[$key] ?? ucfirst($key),
                'visited' => (int) ($visited[$key] ?? 0),
                'viewed' => $n($key === 'parcel' ? 'browse' : 'item'),
                'started' => $n('checkout'),
                'orders' => array_key_exists($key, $moduleSales) ? (int) $moduleSales[$key]['orders'] : null,
            ];
            if ($row['visited'] > 0 || ($row['orders'] ?? 0) > 0) {
                $out[$key] = $row;
            }
        }

        return $out;
    }

    /** Visitors on a cart, booking or checkout page in the last few minutes, newest first, one line each. */
    public function aboutToBook(int $limit = 20): Collection
    {
        $minutes = max(1, (int) config('visitors.about_to_book_minutes', 30));

        return $this->views(now()->subMinutes($minutes))
            ->whereIn('page_type', PageClassifier::BOOKING_TYPES)
            ->with(['user:id,name,phone', 'city:id,name'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(300)->get()
            ->unique('visitor_id')->take($limit)->values();
    }
}
