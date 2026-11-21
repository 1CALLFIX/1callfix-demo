<?php

namespace App\Services\Visitors;

use App\Models\City;
use App\Models\Franchise;
use App\Models\PageView;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Models\VisitorPresence;
use App\Models\Zone;
use App\Services\Customer\CustomerLocationContext;
use App\Support\NativeApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Live Visitors (owner 2026-11-21). Records one ping from public/js/visit-ping.js:
 *   k=view  a customer page was opened → a page_views row + this visitor's presence row
 *   k=beat  the page is still open (once a minute) → presence last_seen_at only
 *
 * Counted: people on customer pages of the website and the customer app. Not counted: bots, staff accounts (Super
 * Admin or anyone with an admin role), the partner and rider apps, non-customer pages, and everything while Super
 * Admin has switched tracking off. Guests are a random browser id only; nothing personal is stored for them.
 * Best-effort: a failure is logged and never shown to the visitor.
 */
class VisitTracker
{
    public const SETTING = 'visitors.tracking';

    public static function enabled(): bool
    {
        $value = Setting::get(self::SETTING);

        return $value === null || trim((string) $value) === '' || (bool) (int) $value;
    }

    /** @return string|null why it was not recorded (for tests and logs), null when recorded */
    public function record(Request $request): ?string
    {
        if (! self::enabled()) {
            return 'off';
        }

        $kind = (string) $request->input('k');
        $visitor = strtolower((string) $request->input('vid'));
        $path = '/'.ltrim((string) parse_url((string) $request->input('p'), PHP_URL_PATH), '/');

        if (! in_array($kind, ['view', 'beat'], true) || ! preg_match('/^[a-f0-9]{32}$/', $visitor) || strlen($path) > 255) {
            return 'invalid';
        }
        if (preg_match((string) config('visitors.bot_pattern'), (string) $request->userAgent())) {
            return 'bot';
        }
        $flavor = NativeApp::flavor();
        if (in_array($flavor, ['partner', 'rider'], true)) {
            return 'partner app';
        }

        /** @var User|null $user */
        $user = $request->user();
        if ($user && ($user->role === 'super_admin' || $user->roleAssignments()->exists())) {
            return 'staff';
        }

        $route = $this->matchRoute($path);
        if (! $route || ! str_starts_with((string) $route->getName(), 'customer.')) {
            return 'not a customer page ('.($route?->getName() ?? $this->matchFailure ?? 'no route').')';
        }

        [$type, $module] = PageClassifier::classify($route->getName());
        if ($route->getName() === 'customer.shop.checkout') {
            $module = $this->storeModule($route->parameter('store')) ?? $module;
        }

        $place = $this->place($route->parameter('city'));
        $title = $this->title((string) $request->input('t'));
        $channel = NativeApp::inApp() ? 'app' : 'web';
        $now = now();

        try {
            $presence = VisitorPresence::find($visitor);
            $newVisit = ! $presence || $presence->last_seen_at->lt($now->copy()->subMinutes((int) config('visitors.visit_gap_minutes', 30)));
            $fields = ['user_id' => $user?->id, 'page_type' => $type, 'module' => $module, 'path' => $path, 'title' => $title, 'channel' => $channel, 'last_seen_at' => $now] + $place;

            if ($kind === 'beat') {
                // Only "still here". Its page is already recorded; a beat with no presence (pruned, first ever) starts one.
                if ($presence && ! $newVisit) {
                    $presence->forceFill(['last_seen_at' => $now, 'user_id' => $user?->id ?? $presence->user_id])->save();
                } else {
                    VisitorPresence::updateOrCreate(['visitor_id' => $visitor], $fields + ['pages' => 0, 'started_at' => $now]);
                }

                return null;
            }

            PageView::create([
                'visitor_id' => $visitor, 'user_id' => $user?->id, 'page_type' => $type, 'module' => $module,
                'route_name' => substr((string) $route->getName(), 0, 60), 'path' => $path, 'title' => $title,
                'channel' => $channel, 'referrer_host' => $this->referrer((string) $request->input('r'), $request->getHost()),
                'created_at' => $now,
            ] + $place);

            VisitorPresence::updateOrCreate(['visitor_id' => $visitor], $fields + [
                'pages' => $newVisit ? 1 : $presence->pages + 1,
                'started_at' => $newVisit ? $now : $presence->started_at,
            ]);
        } catch (\Throwable $e) {
            Log::warning('VisitTracker: could not record a visit.', ['error' => $e->getMessage()]);

            return 'error';
        }

        return null;
    }

    private ?string $matchFailure = null;

    private function matchRoute(string $path): ?\Illuminate\Routing\Route
    {
        try {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));

            return $route->getName() ? $route : null;
        } catch (HttpException $e) {
            $this->matchFailure = class_basename($e);

            return null;
        }
    }

    /**
     * Where the visitor is browsing: the page's own city when its address has one (/nellore/...), else the area
     * picked on the site (session zone). Franchise from the zone, or the city's only franchise.
     *
     * @return array{zone_id: ?int, franchise_id: ?int, city_id: ?int, country_id: ?int}
     */
    private function place(mixed $citySlug): array
    {
        $zoneId = (int) session(CustomerLocationContext::SESSION_KEY, 0);
        $zone = $zoneId ? Cache::remember("visitors.zone.{$zoneId}", 600, function () use ($zoneId) {
            $z = Zone::with('franchise:id,city_id,country_id')->find($zoneId, ['id', 'franchise_id']);

            return $z ? ['zone_id' => $z->id, 'franchise_id' => $z->franchise_id, 'city_id' => $z->franchise?->city_id, 'country_id' => $z->franchise?->country_id] : null;
        }) : null;

        if (is_string($citySlug) && $citySlug !== '') {
            $city = Cache::remember('visitors.city.'.strtolower($citySlug), 600, function () use ($citySlug) {
                $c = City::where('slug', $citySlug)->first(['id', 'country_id']);
                if (! $c) {
                    return null;
                }
                $franchises = Franchise::where('city_id', $c->id)->limit(2)->pluck('id');

                return ['city_id' => $c->id, 'country_id' => $c->country_id, 'franchise_id' => $franchises->count() === 1 ? $franchises->first() : null];
            });

            if ($city) {
                $sameCity = $zone && $zone['city_id'] === $city['city_id'];

                return ['zone_id' => $sameCity ? $zone['zone_id'] : null, 'franchise_id' => $sameCity ? $zone['franchise_id'] : $city['franchise_id'],
                    'city_id' => $city['city_id'], 'country_id' => $city['country_id']];
            }
        }

        return $zone ?? ['zone_id' => null, 'franchise_id' => null, 'city_id' => null, 'country_id' => null];
    }

    private function storeModule(mixed $store): ?string
    {
        $found = $store instanceof Store ? $store : Store::query()->whereKey(is_numeric($store) ? (int) $store : 0)->first(['id', 'module']);

        return $found ? ($found->isRestaurant() ? 'food' : 'shop') : null;
    }

    /** "AC Repair in Nellore | 1CallFix" → "AC Repair in Nellore". */
    private function title(string $title): ?string
    {
        $title = trim(strip_tags($title));
        if (($cut = mb_strrpos($title, ' | ')) !== false && $cut > 0) {
            $title = mb_substr($title, 0, $cut);
        }

        return $title === '' ? null : mb_substr($title, 0, 150);
    }

    private function referrer(string $host, string $ownHost): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '' || $host === strtolower($ownHost) || ! preg_match('/^[a-z0-9.-]{1,100}$/', $host)) {
            return null;
        }

        return $host;
    }
}
