<?php

namespace App\Http\Middleware;

use App\Services\Security\SecurityHeaderSettings as S;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the HTTP security headers the owner has switched on (Admin → System → Security headers). Every value is a
 * setting; nothing here decides policy. A header the response already carries is left alone, HSTS is only ever sent
 * over https, and the Content-Security-Policy is only sent on HTML responses (never on JSON, files or redirects).
 * A settings failure must never take a page down, so any error here just serves the response without the headers.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if (! S::enabled()) {
                return $response;
            }

            $headers = $response->headers;
            $set = function (string $name, ?string $value) use ($headers) {
                if ($value !== null && $value !== '' && ! $headers->has($name)) {
                    $headers->set($name, $value);
                }
            };

            $set('X-Content-Type-Options', S::nosniff() ? 'nosniff' : null);
            $set('Referrer-Policy', S::referrerPolicy());
            $set('X-Frame-Options', S::frameOptions());
            $set('Permissions-Policy', S::permissionsPolicy());

            if ($request->isSecure()) {
                $set('Strict-Transport-Security', S::hsts());
            }

            $isHtml = str_contains((string) $headers->get('Content-Type'), 'text/html');
            if ($isHtml && ! $response->isRedirection()) {
                match (S::cspMode()) {
                    'enforce' => $set('Content-Security-Policy', S::cspPolicy()),
                    'report_only' => $set('Content-Security-Policy-Report-Only', S::cspPolicy()),
                    default => null,
                };
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $response;
    }
}
