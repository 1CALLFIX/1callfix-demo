<?php

namespace App\Http\Middleware;

use App\Services\Seo\SeoSettings;
use App\Support\NativeApp;
use App\Support\Seo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-in (Admin → SEO → Search & social, OFF by default): send a public GET that arrived on a non-canonical host
 * (www., api., an IP) or over http to the canonical origin with a 301, keeping path and query. It never touches:
 * non-GET requests, /api/*, the health check, Livewire and storage — so the mobile API, webhooks and uploads keep
 * working on whatever host they use today. Turning it on is the owner's host-cutover decision, not a default.
 *
 * The Android apps are never sent to the canonical host: they stay on www.1callfix.com, where their people are
 * signed in (owner 2026-11-24, "address redirect to the other": browsers -> 1callfix.com, apps -> www).
 */
class RedirectToCanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)
            || $request->is('api', 'api/*', 'up', 'livewire', 'livewire/*', 'livewire-*', 'storage/*')) {
            return $next($request);
        }

        // The Android apps live on their own address (www, config app.native_app_url) and are signed in only there
        // (owner 2026-11-24: a page opened on 1callfix.com showed them signed out). Always on, apps only, 302: a page
        // of ours opened on the other address goes back to the app's address. Browsers and Google never get this.
        if (NativeApp::inApp()) {
            $app = parse_url((string) config('app.native_app_url'));
            $appHost = strtolower((string) ($app['host'] ?? ''));
            $bare = fn (string $h) => preg_replace('/^www\./', '', strtolower($h));
            if ($appHost !== '' && strcasecmp($request->getHost(), $appHost) !== 0 && $bare($request->getHost()) === $bare($appHost)) {
                return redirect()->to(($app['scheme'] ?? 'https').'://'.$appHost.$request->getRequestUri(), 302);
            }

            return $next($request);
        }

        if (! SeoSettings::redirectHostEnabled()) {
            return $next($request);
        }

        $base = parse_url(Seo::canonicalBase());
        $host = strtolower((string) ($base['host'] ?? ''));
        $scheme = strtolower((string) ($base['scheme'] ?? 'https'));

        if ($host === '' || (strcasecmp($request->getHost(), $host) === 0 && $request->getScheme() === $scheme)) {
            return $next($request);
        }

        return redirect()->to($scheme.'://'.$host.($base['port'] ?? false ? ':'.$base['port'] : '').$request->getRequestUri(), 301);
    }
}
