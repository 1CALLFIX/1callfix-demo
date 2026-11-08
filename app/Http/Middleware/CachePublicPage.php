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

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->eligible($request)) {
            return $next($request);
        }

        $key = PageCache::key($request->path(), app()->getLocale(), NativeApp::inApp() ? (NativeApp::flavor() ?? 'app') : 'web');
        $token = (string) $request->session()->token();

        if (is_string($html = PageCache::get($key))) {
            return response(str_replace(PageCache::TOKEN_MARK, $token, $html), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Page-Cache' => 'HIT',
            ]);
        }

        $before = $this->sessionShape($request);
        $response = $next($request);

        if ($this->storable($request, $response, $before) && $token !== '') {
            PageCache::put($key, str_replace($token, PageCache::TOKEN_MARK, (string) $response->getContent()));
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

    private function sessionShape(Request $request): string
    {
        return json_encode(array_diff_key($request->session()->all(), ['_previous' => 1])) ?: '';
    }

    private function storable(Request $request, Response $response, string $before): bool
    {
        return $response->getStatusCode() === 200
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            && ! $response->headers->has('Location')
            && Cookie::getQueuedCookies() === []
            && $this->sessionShape($request) === $before
            && ! auth()->check();
    }
}
