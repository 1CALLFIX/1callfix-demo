# Release 2026-11-08 — "Get the app" section, /app link + Redis (cache and queue)

Use with `docs/DEPLOY_RUNBOOK.md`. Ships together with `96f49b6` (city SEO, see 2026-11-08-city-seo.md).

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `e36fa57` |
| Full test suite | see commit message; known failure only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes |
| Version | `VERSION` file bumped to `2026.11.08.5` |

## What's in it
- Homepage section "Get the app" (Homepage sections → "Get the app", movable/hideable): Play Store badge, QR code on
  computers, iPhone "Add to Home Screen" steps, drawn phone picture. Never inside the Android apps.
- `/app` page (in the sitemap) — the one link to print, post and share. `/app?go=1` (the QR code) sends Android
  phones straight to the Play Store.
- Footer: Play Store badge always (Partner page → App links overrides the link) + "Get the app".
- Words: Website text → "Get the app section", en + te.
- `deploy/supervisor/onecallfix-worker.conf`: workers no longer name the `database` connection; they follow
  QUEUE_CONNECTION in .env.

## Redis switch (after the code deploy, owner + Claude together)
Sessions stay on files. Cache and queue move to Redis.
1. Owner, as root: put the real Redis password into .env without printing it:
   `PW=$(awk '/^requirepass/{print $2}' /etc/redis/redis.conf) && sed -i "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=\"$PW\"|" /home/1callfix.com/public_html/.env && unset PW`
2. Claude: check Laravel reaches Redis (ping), confirm the database queue is empty.
3. Owner, as root: install the new worker file (`cp -i` asks to overwrite: answer y):
   `cp -i /home/1callfix.com/public_html/deploy/supervisor/onecallfix-worker.conf /etc/supervisord.d/onecallfix-worker.ini && supervisorctl reread && supervisorctl update`
4. Claude: .env `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `REDIS_QUEUE_RETRY_AFTER=150` (longer than the
   workers' 120 s timeout, so a slow job is never run twice), then the four-phase cache clear.
5. Owner, as root, straight after step 4: `supervisorctl restart onecallfix-worker:* && supervisorctl status | grep onecallfix`
6. Claude: place a test push / check worker logs, failed_jobs unchanged, live pages 200.

Rollback: .env back to `database` for both, config:cache, restart workers.

## Step 5 — migrations that must be pending
- none

## Step 10 — scheduled commands
- none new
