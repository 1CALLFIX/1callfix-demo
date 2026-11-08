# Release 2026-11-08 — the web server's own page cache (LiteSpeed)

Use with `docs/DEPLOY_RUNBOOK.md`. Ships OFF (`LSCACHE` unset); switched on after the checks below.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `1e51720` (probe: `/_lsprobe` showed `x-litespeed-cache: hit` on OpenLiteSpeed 1.8.5, no root change) |
| Full test suite | 4642 tests, 4641 passed; known failure only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build | yes (resources/js/lscache.js) |
| Version | `VERSION` file bumped to `2026.11.08.10` |

## What's in it (app/Support/LiteSpeed.php)
- A ready-made page (CachePublicPage) for a website visitor with no zone and the default language is also marked
  `X-LiteSpeed-Cache-Control: public, max-age=60`, sent with NO cookies and an EMPTY security token. The web
  server then hands it out without PHP.
- resources/js/lscache.js fills the visitor's own token back in from the `cf_csrf` cookie, or from `GET /_hello`
  (which also starts the session and, on a city page, sets the city as building the page would).
- public/.htaccess sends to PHP anyone with `cf_nc` (signed in / personal), `cf_zone`, `cf_locale`, or the apps'
  user agent. `LiteSpeedCache` (global middleware) keeps those cookies in step with the session.
- Any admin save → `X-LiteSpeed-Purge: *`.
- /partners and /franchise now get ready-made pages too (ad landing pages).

## Switch
- On: `LSCACHE=true` in .env, then `php artisan config:cache`.
- Off: remove it / `LSCACHE=false`, `php artisan config:cache`. Saved copies expire within 60 s.

## Checks after switching on
- New visitor: `/app` twice → `x-litespeed-cache: hit`, no `set-cookie`, empty csrf meta.
- With cookie `cf_nc=1`, `cf_zone=1`, `cf_locale=en`, or the app user agent: never `hit`.
- `/_hello?path=/nellore/services` → JSON token + `cf_csrf` and `cf_zone` cookies.
- Browser: search, add to cart, book, partner sign-up work on a page served by the web server.

## Step 5 — migrations: none
## Step 10 — scheduled commands: none new
