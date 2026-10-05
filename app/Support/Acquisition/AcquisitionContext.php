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
}
