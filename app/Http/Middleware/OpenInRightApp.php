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

        // Owner 2026-11-09: "in the rider app it's showing the customer app" after signing in or creating an account.
        // Every sign-in / sign-up path that ends on the customer homepage lands on the app's own home instead.
        if ($name === 'customer.home') {
            return redirect()->to($this->appHome($request, $flavor));
        }

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

    /** The app's own start: partner → its start page; rider → his dashboard, the rider sign-in, or the join page. */
    private function appHome(Request $request, string $flavor): string
    {
        if ($flavor === 'partner') {
            return route('partner.app.start');
        }

        $user = $request->user();
        if (! $user) {
            return route('rider.login');
        }
        $worker = $user->fieldWorkerProfile;

        // Same test as EnsureIsParcelRider, so this never lands on its "not a rider" page.
        return $worker && $worker->is_active && $worker->hasCapability('parcel_rider')
            ? route('rider.dashboard')
            : route('customer.partners');
    }
}
