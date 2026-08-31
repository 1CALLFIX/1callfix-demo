<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Banner carousel rotation intervals (milliseconds)
    |--------------------------------------------------------------------------
    |
    | How long each slide stays on screen before the carousel auto-advances,
    | per on-screen slot. The hero sits at the top of the homepage and holds
    | the eye longer, so it rotates noticeably slower than the mid-page strip.
    |
    | These are tunable starting points, not fixed values — adjust them here
    | (or via the BANNER_HERO_ROTATION_MS / BANNER_MID_ROTATION_MS env vars)
    | without touching the carousel component or its JavaScript. The Blade
    | component reads whatever it is handed as an `:interval` prop and passes
    | it straight to carousel.js via a data attribute; nothing about the
    | timing is hard-coded in either place any more.
    |
    | carousel.js falls back to 6000ms only if a call site passes no interval
    | at all.
    |
    */

    'hero_rotation_ms' => (int) env('BANNER_HERO_ROTATION_MS', 9000),

    'mid_rotation_ms' => (int) env('BANNER_MID_ROTATION_MS', 3500),

];
