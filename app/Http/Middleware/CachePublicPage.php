<?php

namespace App\Http\Middleware;

use App\Support\NativeApp;
use App\Support\PageCache;
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
 *  - the visitor's session holds anything beyond Laravel's own keys (chosen location, guest cart, intended URL,
 *    flash message…): every per-visitor choice lives in the session, so "nothing personal" is checkable;
 *  - building the page changed the session or queued a cookie (then it depended on this visitor);
 *  - the page is not a 200 HTML page.
 * Kept separately per language and per app (website / customer / rider / partner app). Each visitor's own security
 * token (CSRF) is put back into the page on the way out, so forms and Livewire keep working.
 * Any admin save of a setting or catalog item clears every saved page at once (PageCache::flush).
 */
class CachePublicPage
{
    /** Session keys Laravel itself keeps for every visitor; anything else means "personal". */
    private const NEUTRAL_SESSION_KEYS = ['_token', '_previous', '_flash'];

    /**
     * Session values a page may set for a brand-new visitor and that are the same for everyone, so they are saved
     * with the page and set again on every saved copy: a city page points a new visitor at that city's first zone
     * (CityContext::enter), which later pages (booking, prices) rely on.
     */
    private const REPLAYED_SESSION_KEYS = [\App\Services\Customer\CustomerLocationContext::SESSION_KEY];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->eligible($request)) {
            return $next($request);
        }

        $key = PageCache::key($request->path(), app()->getLocale(), NativeApp::inApp() ? (NativeApp::flavor() ?? 'app') : 'web');
        $token = (string) $request->session()->token();

        if (is_array($saved = PageCache::get($key))) {
            foreach ($saved['session'] as $k => $v) {
                session([$k => $v]);
            }

            return response(str_replace(PageCache::TOKEN_MARK, $token, $saved['html']), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Page-Cache' => 'HIT',
            ]);
        }

        $before = $this->sessionShape($request);
        $response = $next($request);

        $replay = [];
        foreach (self::REPLAYED_SESSION_KEYS as $k) {
            if ($request->session()->has($k)) {
                $replay[$k] = $request->session()->get($k);
            }
        }
        if ($this->storable($request, $response, $before, array_keys($replay)) && $token !== '') {
            PageCache::put($key, [
                'html' => str_replace($token, PageCache::TOKEN_MARK, (string) $response->getContent()),
                'session' => $replay,
            ]);
            $response->headers->set('X-Page-Cache', 'MISS');
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

        return $this->onlyNeutral($request->session()->all());
    }

    /** @param array<string, mixed> $session */
    private function onlyNeutral(array $session): bool
    {
        foreach ($session as $k => $v) {
            if (! in_array($k, self::NEUTRAL_SESSION_KEYS, true)) {
                return false;
            }
        }
        // A flash message or old form input is for this visitor only.
        $flash = $session['_flash'] ?? [];

        return empty($flash['new']) && empty($flash['old']);
    }

    /** @param list<string> $ignore */
    private function sessionShape(Request $request, array $ignore = []): string
    {
        // Dotted keys ("customer.zone_id") live nested; drop them, then any group they leave empty.
        $all = \Illuminate\Support\Arr::except($request->session()->all(), ['_previous', ...$ignore]);

        return json_encode(array_filter($all, fn ($v) => $v !== [])) ?: '';
    }

    /** @param list<string> $replayed session keys the page set that are saved with it */
    private function storable(Request $request, Response $response, string $before, array $replayed): bool
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
            && $this->sessionShape($request, $replayed) === $before
            && ! auth()->check();
    }
}
