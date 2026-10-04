<?php

namespace App\Http\Middleware;

use App\Support\Acquisition\AcquisitionSanitizer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * F1: first-touch marketing attribution. The first visit carrying a utm_* / click-id parameter is remembered in
 * the session and in a 30-day cookie; later visits never overwrite it. Booking creation copies it onto the booking.
 */
class CaptureAcquisition
{
    public const COOKIE = 'cf_acq';
    public const SESSION_KEY = 'acquisition';
    private const COOKIE_MINUTES = 60 * 24 * 30;

    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('GET') && $request->hasSession()) {
            $this->capture($request);
        }

        return $next($request);
    }

    private function capture(Request $request): void
    {
        if ($request->session()->has(self::SESSION_KEY)) {
            return;
        }

        $existing = AcquisitionSanitizer::clean(json_decode((string) $request->cookie(self::COOKIE), true));
        if ($existing) {
            $request->session()->put(self::SESSION_KEY, $existing);

            return;
        }

        if (! AcquisitionSanitizer::clean($request->query())) {
            return;
        }

        $host = parse_url((string) $request->headers->get('referer'), PHP_URL_HOST);
        $found = AcquisitionSanitizer::clean($request->query() + [
            'landing_path' => '/'.ltrim($request->path(), '/'),
            'referrer_host' => is_string($host) && $host !== $request->getHost() ? $host : null,
            'captured_at' => now()->toIso8601String(),
        ]);

        $request->session()->put(self::SESSION_KEY, $found);
        Cookie::queue(self::COOKIE, json_encode($found), self::COOKIE_MINUTES);
    }
}
