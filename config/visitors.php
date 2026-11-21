<?php

// Live Visitors (owner 2026-11-21). Switched on/off by Super Admin on the screen itself (setting visitors.tracking).

return [

    // Seconds between 'still here' pings from an open page. Raise to 120 if the server gets busy (then set online_minutes to 5).
    'beat_seconds' => (int) env('VISITORS_BEAT_SECONDS', 60),

    // "Online now" = a ping in the last N minutes. An open page pings once a minute.
    'online_minutes' => (int) env('VISITORS_ONLINE_MINUTES', 3),

    // "About to book" = on a cart, booking or checkout page in the last N minutes.
    'about_to_book_minutes' => (int) env('VISITORS_ABOUT_TO_BOOK_MINUTES', 30),

    // Page history is deleted after this many days (visitors:prune, daily).
    'keep_days' => (int) env('VISITORS_KEEP_DAYS', 90),

    // A gap longer than this starts a new visit.
    'visit_gap_minutes' => 30,

    // Browsers and tools that are not people. They rarely run the ping script anyway; this catches the ones that do.
    'bot_pattern' => '/bot|crawl|spider|slurp|lighthouse|headless|pagespeed|preview|facebookexternalhit|python|curl|wget|playwright|puppeteer|selenium/i',

];
