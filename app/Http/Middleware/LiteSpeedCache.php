<?php

namespace App\Http\Middleware;

use App\Support\LiteSpeed;
use App\Support\PageCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Last word on every response for the web server's page cache (see App\Support\LiteSpeed). Global, so it runs after
 * the session and cookie middleware have added their cookies:
 *  - a response CachePublicPage marked: every cookie removed (a kept copy must never hand one visitor's session to
 *    another) and X-LiteSpeed-Cache-Control: public set;
 *  - any other page with a session: keeps cf_nc / cf_zone / cf_csrf in step with the visitor's session;
 *  - after an admin save (PageCache::flush): X-LiteSpeed-Purge: * so the server drops its copies at once.
 */
class LiteSpeedCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! LiteSpeed::enabled()) {
            $response->headers->remove(LiteSpeed::MARK);

            return $response;
        }

        if (LiteSpeed::$purge) {
            $response->headers->set('X-LiteSpeed-Purge', '*');
            LiteSpeed::$purge = false;
        }

        if ($response->headers->has(LiteSpeed::MARK)) {
            $response->headers->remove(LiteSpeed::MARK);
            foreach ($response->headers->getCookies() as $cookie) {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
            $response->headers->set('X-LiteSpeed-Cache-Control', 'public, max-age='.LiteSpeed::TTL);

            return $response;
        }

        if ($request->hasSession()) {
            $this->syncCookies($request, $response);
        }

        return $response;
    }

    private function syncCookies(Request $request, Response $response): void
    {
        $session = $request->session();
        $minutes = (int) config('session.lifetime', 120);

        $personal = auth()->check() || PageCache::isPersonal($session->all());
        $this->keep($request, $response, LiteSpeed::COOKIE_PERSONAL, $personal ? '1' : null, $minutes, true);

        $zone = (int) $session->get(\App\Services\Customer\CustomerLocationContext::SESSION_KEY, 0);
        $this->keep($request, $response, LiteSpeed::COOKIE_ZONE, $zone > 0 ? (string) $zone : null, $minutes, true);

        $this->keep($request, $response, LiteSpeed::COOKIE_TOKEN, (string) $session->token() ?: null, $minutes, false);
    }

    /** Sets the cookie when its value changed, removes it when it should be gone; untouched otherwise. */
    private function keep(Request $request, Response $response, string $name, ?string $value, int $minutes, bool $httpOnly): void
    {
        $current = $request->cookies->get($name);
        if ($value === null) {
            if ($current !== null) {
                $response->headers->clearCookie($name, '/', null, true, $httpOnly, false, 'lax');
            }

            return;
        }
        if ($current !== $value) {
            $response->headers->setCookie(Cookie::create($name, $value, now()->addMinutes($minutes), '/', null, true, $httpOnly, false, 'lax'));
        }
    }
}
