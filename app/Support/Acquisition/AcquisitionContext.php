<?php

namespace App\Support\Acquisition;

use App\Http\Middleware\CaptureAcquisition;

class AcquisitionContext
{
    /** First-touch attribution of the current web session, or null (CLI, API, queue, nothing captured). */
    public static function current(): ?array
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }
        if (! app()->bound('request')) {
            return null;
        }
        $request = request();

        return $request->hasSession()
            ? AcquisitionSanitizer::clean($request->session()->get(CaptureAcquisition::SESSION_KEY))
            : null;
    }

    /**
     * F3: remember the city (slug) of the first city-scoped page on an existing first-touch record.
     * Never creates a record on its own (a city alone is not attribution) and never overwrites an earlier city.
     */
    public static function stampCity(\Illuminate\Http\Request $request, string $citySlug): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $current = AcquisitionSanitizer::clean($request->session()->get(CaptureAcquisition::SESSION_KEY));
        if ($current === null || isset($current['city'])) {
            return;
        }

        $current['city'] = $citySlug;
        $request->session()->put(CaptureAcquisition::SESSION_KEY, $current);
        \Illuminate\Support\Facades\Cookie::queue(CaptureAcquisition::COOKIE, json_encode($current), 60 * 24 * 30);
    }
}
