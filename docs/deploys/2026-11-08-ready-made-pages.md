# Release 2026-11-08 — ready-made public pages (page cache)

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `a47280c` |
| Full test suite | see commit message; known failure only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build | no change (scp anyway is harmless) |
| Version | `VERSION` file bumped to `2026.11.08.6` |

## What's in it
- Load test 2026-11-08: 2 processors build about 15 pages a second. The homepage, /app, /services and the city
  pages (hub, services, service/category, parcel, food, restaurant, shop, store, product) are now kept for 60 s and
  handed out again to visitors who have not signed in and have nothing personal in their session (location, guest
  cart, flash message). Signed-in, personal, query-string and private pages are built fresh exactly as before.
- One copy per page, language and app (website / customer / rider / partner app). Each visitor's own CSRF token is
  put back on the way out.
- Any save of a setting or catalog item (services, categories, banners, shops, products, cities, zones, FAQs…)
  clears all saved pages at once.
- Response header `X-Page-Cache: HIT|MISS` shows it working.
- Emergency off switch: `PAGE_CACHE=false` in .env + `php artisan config:cache`.

## Step 5 — migrations: none
## Step 10 — scheduled commands: none new

## Checks after deploy
- `curl -sI https://www.1callfix.com/app` twice: MISS then HIT.
- Signed-in browser: pages still show your name/cart; no X-Page-Cache header.
- Re-run the read-only load test and compare with 15 pages/s.
