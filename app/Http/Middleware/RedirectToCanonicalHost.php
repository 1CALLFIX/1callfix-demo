<?php

namespace App\Http\Middleware;

use App\Services\Seo\SeoSettings;
use App\Support\Seo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-in (Admin → SEO → Search & social, OFF by default): send a public GET that arrived on a non-canonical host
 * (www., api., an IP) or over http to the canonical origin with a 301, keeping path and query. It never touches:
 * non-GET requests, /api/*, the health check, Livewire and storage — so the mobile API, webhooks and uploads keep
 * working on whatever host they use today. Turning it on is the owner's host-cutover decision, not a default.
 */
class RedirectToCanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)
            || $request->is('api', 'api/*', 'up', 'livewire', 'livewire/*', 'livewire-*', 'storage/*')
            || ! SeoSettings::redirectHostEnabled()) {
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
