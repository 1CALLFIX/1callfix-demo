# Release 2026-11-19.2 — Settings that are not set are cached too

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `d996080` (2026-11-19 Rent anything) |
| Full test suite | 4993/4994 passed (2026-11-19, final code); known failure only: NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default (.env) |
| Composer | no (`composer.lock` unchanged) |
| Front-end build (steps 6–7) | no (PHP only) |
| Version | `VERSION` 2026.11.19.2 |

## What's in it
- Found in the 2026-11-19 local walk-through (pages timed out at 30 s). `Setting::get` (`app/Models/Setting.php`)
  cached only keys that had a value: Laravel's `rememberForever` treats a cached null as "not cached", so every
  setting that is not set (most of them: 242 of the 300 setting entries in the old cache table on live) went to MySQL
  and wrote the cache again **on every lookup, on every page**.
- Now "not set" is cached as well (`['v' => null]`). `Setting::set()` and `Setting::clear()` still forget the key, so
  changes in Admin show at once. A value cached before this release still reads (it is a plain string).
- The Setting model now also forgets its cached key whenever a row is saved or deleted through the model
  (`updateOrCreate`, `save`, `delete`), not only through `set()` / `clear()`. Every write in `app/` already goes through
  `set()` / `clear()` (checked); 42 places in the tests write directly and needed this. Migrations that insert settings
  with `DB::table` run before the step 8 cache clear, so nothing stale is left.
- The new listener returns nothing on purpose: a model-event listener that returns `false` stops the ones after it
  (sitemap version bump, page cache flush). Caught by `SeoAdminSettingsTest` during this release.
- Two "no N+1" tests (`ParcelOrdersPerformanceTest`, `PropertyRentalApiTest`) now warm the screen once before
  counting, since settings are cached after the first read.
- Live uses Redis for the cache, so the gain there is fewer MySQL queries per page rather than seconds; locally
  (database cache on SQLite) 300 lookups went from ~11.6 s on every page to 0.03 s after the first.
- Test: `tests/Feature/Settings/SettingCacheTest.php` (the "read once, then cached" test fails on the old code).

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Home, `/nellore`, `/nellore/rentals`, `/admin/login` open (200).
- Admin → Rental Settings: change one setting for a zone, Save, and the customer page follows at once (e.g. switch
  "Rent anything" on for the pilot zone and `/nellore/rent` opens).

## Admin settings to do after deploy (owner)
- none
