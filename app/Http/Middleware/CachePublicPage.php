<?php

namespace App\Http\Middleware;

use App\Support\LiteSpeed;
use App\Support\NativeApp;
use App\Support\PageCache;
use App\Support\SiteText;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ready-made public pages (owner 2026-11-08, after the load test: 2 processors build ~15 pages a second).
 *
 * The homepage, city, service, food and shop pages look the same for every visitor who has not signed in and has
 * nothing personal yet, so the finished page is kept for PageCache::TTL seconds and handed out again. Built fresh,
 * exactly as before, whenever ANY of these is true:
 *  - signed in; any query string (?page, ?q, ?utm_…, previews); not a plain GET;
 *  - the visitor's session holds anything beyond Laravel's own keys and the area's zone (a typed address or map
 *    pin, guest cart, intended URL, flash message…): every per-visitor choice lives in the session, so "nothing
 *    personal" is checkable. The zone alone is shared by everyone in that area, so pages are kept per zone;
 *  - building the page changed the session or queued a cookie (then it depended on this visitor);
 *  - the page is not a 200 HTML page.
 * Kept separately per language and per app (website / customer / rider / partner app). Each visitor's own security
 * token (CSRF) is put back into the page on the way out, so forms and Livewire keep working.
 * Any admin save of a setting or catalog item clears every saved page at once (PageCache::flush).
 *
 * The web server's own cache (App\Support\LiteSpeed, LSCACHE switch): a website visitor with no zone and the default
 * language gets the copy with an EMPTY token, marked so the web server may keep it and hand it out without PHP;
 * resources/js/lscache.js fills the visitor's own token in (and, on a city page, asks /_hello to set the city).
 */
class CachePublicPage
{
    /**
     * Session values a page may set for a brand-new visitor and that are the same for everyone, so they are saved
     * with the page and set again on every saved copy: a city page points a new visitor at that city's first zone
     * (CityContext::enter), which later pages (booking, prices) rely on.
     */
    private const REPLAYED_SESSION_KEYS = [self::ZONE_KEY];

    private const ZONE_KEY = \App\Services\Customer\CustomerLocationContext::SESSION_KEY;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->eligible($request)) {
            return $next($request);
        }

        // The zone is shared by everyone in it (not personal), but pages differ by zone: one copy per zone.
        $zone = (int) $request->session()->get(self::ZONE_KEY, 0);
        $key = PageCache::key($request->path().'|z'.$zone, app()->getLocale(), NativeApp::inApp() ? (NativeApp::flavor() ?? 'app') : 'web');
        $token = (string) $request->session()->token();
        $webServer = $this->webServerMayKeep($request, $zone);

        if (is_array($saved = PageCache::get($key))) {
            foreach ($saved['session'] as $k => $v) {
                session([$k => $v]);
            }

            if ($webServer) {
                return response($this->forWebServer($saved['html'], PageCache::TOKEN_MARK, $saved['session'] !== []), 200, [
                    'Content-Type' => 'text/html; charset=UTF-8',
                    LiteSpeed::MARK => '1',
                ]);
            }

            return response(str_replace(PageCache::TOKEN_MARK, $token, $saved['html']), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Page-Cache' => 'HIT',
            ]);
        }

        $before = $this->sessionShape($request, self::REPLAYED_SESSION_KEYS);
        $response = $next($request);

        $replay = [];
        foreach (self::REPLAYED_SESSION_KEYS as $k) {
            if ($request->session()->has($k)) {
                $replay[$k] = $request->session()->get($k);
            }
        }
        if ($this->storable($request, $response, $before) && $token !== '') {
            $html = (string) $response->getContent();
            PageCache::put($key, [
                'html' => str_replace($token, PageCache::TOKEN_MARK, $html),
                'session' => $replay,
            ]);
            $response->headers->set('X-Page-Cache', 'MISS');

            if ($webServer) {
                $response->setContent($this->forWebServer($html, $token, $replay !== []));
                $response->headers->set(LiteSpeed::MARK, '1');
            }
        }

        return $response;
    }

    private function eligible(Request $request): bool
    {
        if (! PageCache::enabled() || ! $request->isMethod('GET') || $request->getQueryString() !== null) {
            return false;
        }
        if (! $request->hasSession() || auth()->check()) {
            return false;
        }

        return ! PageCache::isPersonal($request->session()->all());
    }

    /**
     * The web server may keep this copy for everyone it serves: a website visitor (the apps are sent to PHP by
     * public/.htaccess), no zone yet and the default language (visitors with cf_zone / cf_locale go to PHP too).
     */
    private function webServerMayKeep(Request $request, int $zone): bool
    {
        return LiteSpeed::enabled() && ! NativeApp::inApp() && $zone === 0 && ! $request->cookies->has(SiteText::COOKIE);
    }

    /** The copy for the web server: no security token (lscache.js fills it in) and, if the page sets the city, a hint. */
    private function forWebServer(string $html, string $token, bool $setsCity): string
    {
        $html = str_replace($token, '', $html);

        return $setsCity ? preg_replace('/<head>/', "<head>\n    <meta name=\"cf-hello\" content=\"1\">", $html, 1) : $html;
    }

    /** @param list<string> $ignore */
    private function sessionShape(Request $request, array $ignore = []): string
    {
        // Dotted keys ("customer.zone_id") live nested; drop them, then any group they leave empty.
        $all = \Illuminate\Support\Arr::except($request->session()->all(), ['_previous', ...$ignore]);

        return json_encode(array_filter($all, fn ($v) => $v !== [])) ?: '';
    }

    private function storable(Request $request, Response $response, string $before): bool
    {
        // The city pages (CityRouteController) return a plain Response whose type is only filled in when it is sent.
        $type = $response->headers->get('Content-Type');
        $html = $type === null
            ? str_contains(substr((string) $response->getContent(), 0, 200), '<html')
            : str_contains($type, 'text/html');

        return $response->getStatusCode() === 200
            && $html
            && ! $response->headers->has('Location')
            && Cookie::getQueuedCookies() === []
            && $this->sessionShape($request, self::REPLAYED_SESSION_KEYS) === $before
            && ! auth()->check();
    }
}
