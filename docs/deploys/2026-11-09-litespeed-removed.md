# Release 2026-11-09 — LiteSpeed page cache removed

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Live before | `0c6bbce` (LSCACHE=false since 2026-11-09 09:00 IST) |
| Migrations | none |
| Front-end build | yes (resources/js/lscache.js removed from app.js) |
| New scheduled commands | none |
| Worker restart | not needed (web code only) |

## Why
Three live tests (2026-11-08/09) showed OpenLiteSpeed on this server hands its saved page to visitors with
cookies (signed in, zone, language, the apps) whatever the site says: the `.htaccess` rules, `X-LiteSpeed-Vary`,
and the vhost settings `reqCookieCache 0` / `ignoreReqCacheCtrl 0` were all ignored. Each time it was switched off
at once; saved copies never held anything personal (no cookies, empty token). Owner approved removing it.

## What's removed
`App\Support\LiteSpeed`, `LiteSpeedCache` middleware and its cookie exclusions, `/_hello` (SessionHelloController),
`/_lsprobe`, `resources/js/lscache.js`, the `lscache` config switch, the `.htaccess` CacheLookup and no-cache rule,
and their tests. Supersedes `2026-11-08-litespeed-cache.md`.

## What stays
The ready-made pages in Redis (`CachePublicPage`, `PageCache`), including `/partners` and `/franchise`.
`LSCACHE` in the server .env is now unused (harmless).

## Checks after deploy
- `/`, `/partners`, `/app`, `/franchise`: `x-page-cache: HIT` on the 2nd visit, no `x-litespeed-cache` header,
  own 40-char token in `<meta name="csrf-token">`.
- `/_lsprobe` and `/_hello` → 404 (or a city 404 page).
