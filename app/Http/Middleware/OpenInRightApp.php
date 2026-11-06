<?php

namespace App\Http\Middleware;

use App\Support\NativeApp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owner rule 2026-11-06: deliveries live in the Rider app; services, shops and restaurants in the Partner app.
 * One account may use both. If a page of one of them is opened inside the other app (an old link, a redirect after
 * sign-up), show a short "open the other app" page instead of mixing the two apps' screens. Browsers and the
 * Customer app are not affected.
 */
class OpenInRightApp
{
    /** Route name prefix => the app it belongs to. */
    private const AREAS = [
        'provider.' => 'partner',
        'seller.' => 'partner',
        'rider.' => 'rider',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $flavor = NativeApp::flavor();
        if (! in_array($flavor, ['partner', 'rider'], true) || ! $request->isMethod('GET')) {
            return $next($request);
        }

        $name = (string) optional($request->route())->getName();
        foreach (self::AREAS as $prefix => $app) {
            if (str_starts_with($name, $prefix) && $app !== $flavor) {
                return response()->view('partner.open-other-app', [
                    'app' => $app,
                    'storeUrl' => 'https://play.google.com/store/apps/details?id='.NativeApp::PACKAGES[$app],
                ]);
            }
        }

        return $next($request);
    }
}
