<?php

namespace App\Http\Controllers;

use App\Services\Visitors\VisitTracker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** POST /visit-ping from public/js/visit-ping.js (Live Visitors, 2026-11-21). Always 204: the page never waits on it. */
class VisitPingController extends Controller
{
    public function __invoke(Request $request, VisitTracker $tracker): Response
    {
        $tracker->record($request);

        return response()->noContent();
    }
}
