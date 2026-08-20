# 1CallFix — Production Deployment Runbook

**Written:** Production Hardening session, 2026-08-20.
**Target:** the one real production environment — Hostinger VPS
(`srv1422426.hstgr.cloud`), CyberPanel + OpenLiteSpeed, live at
`https://api.1callfix.com`. Server path: `/home/1callfix.com/public_html/api/`.
Deploy user: `callf1207` (prefer this over `root` — using `root` for
git/deploy operations has caused file-permission issues before; see
`PROJECT_HANDOFF.md` §9).

**There is no separate staging environment.** Per `PROJECT_CURRENT_STATE.md`
§2: deploys go directly against the production checkout via SCP or `git
pull`. Treat every deploy accordingly — the checks in this runbook are not
optional formality, they are the only safety net that exists.

**Production data volume as of the last confirmed check (2026-08-20):** 0
bookings, 0 commissions, 0 coupons, 2 users, 4 franchises, 3 zones — a
pre-launch/early-setup state, not live customer traffic. Re-confirm this is
still true before assuming a deploy mistake is "low stakes" — it will not
stay true once real bookings exist.

---

## 0. Before you start — things this runbook assumes you already resolved

- **Backups are NOT confirmed to exist for this application today.**
  `KNOWN_RISKS_AND_DECISIONS.md` item 21 found no in-app backup tooling
  (no `spatie/laravel-backup`, no backup Artisan command, no admin screen)
  and could **not** confirm from this repo/session whether the "nightly
  `mysqldump` + weekly Hostinger VPS snapshot" described in the older
  `PROJECT_HANDOFF.md` §9 is actually still running on the real server —
  that document predates the item-21 audit and was never re-verified
  against it. **Do not assume a backup exists. Step 1 below is mandatory,
  not a formality**, until someone confirms server-side automated backups
  are real and working (SSH in, check `storage/backups/` and/or the
  Hostinger snapshot schedule directly).
- **Real credentials** (Razorpay live keys, Arkesel/FCM, a real
  `ANTHROPIC_API_KEY` if the AI features are to produce LLM-phrased prose)
  are provisioned directly on the server's own `.env` by whoever owns
  those accounts — never through this repo, never through git, never by
  an AI coding session. See `.env.example` for the full list of what's
  required vs. optional and why (each block has its own comment
  explaining the real vendor decision behind it).
- **Terms & Conditions / Privacy Policy** do not exist as real content
  (`KNOWN_RISKS_AND_DECISIONS.md` item 17) — this is a legal/business gap,
  not something a deploy fixes. Real user signup should not go live
  without this being addressed by someone with the authority to write
  binding legal text.

---

## 1. Pre-deploy checklist

Run through this in order. Stop and fix if any step fails — do not
proceed to Section 2 with an unresolved failure here.

1. **Backup the database, for real, right now.**
   ```bash
   ssh callf1207@srv1422426.hstgr.cloud
   mysqldump -u <db_user> -p 1cal_api > ~/backups/1cal_api_$(date +%Y%m%d_%H%M%S).sql
   ```
   Confirm the dump file is non-empty and its size is in the same ballpark
   as the last one (`ls -lh ~/backups/`). A 0-byte or truncated dump is
   worse than no backup — it gives false confidence. This step is required
   before EVERY deploy that includes a migration, no exceptions, until
   real automated backups are confirmed running independently (see §0).

2. **Confirm the target branch is what you think it is** and has already
   passed the full test suite locally:
   ```bash
   git log -1 --oneline
   php artisan test
   ```
   Every deploy ships code that was green locally first. This repo's own
   convention (see `CURRENT_MASTER_CHECKPOINT.md`/`KNOWN_RISKS_AND_DECISIONS.md`
   history) is full-suite-green before every commit — a deploy is not the
   place to discover a regression.

3. **Confirm production `.env` values** (SSH in, `cat .env` — never paste
   its contents anywhere outside that terminal):
   - `APP_ENV=production`
   - `APP_DEBUG=false` — **verify this one explicitly, every time.** This
     exact mistake already happened once for real (`KNOWN_RISKS_AND_DECISIONS.md`
     item 25) and leaked full stack traces on every 500 until caught via
     direct SSH inspection. `.env.example` now documents this loudly, but
     the example file cannot enforce the real server's `.env`.
   - `APP_KEY` is set, unique to this environment, and was never copied
     from `.env.example` or another environment.
   - `SESSION_SECURE_COOKIE=true` (the site is served over HTTPS; a cookie
     without this flag can leak over a plain-HTTP connection if one is
     ever possible).
   - `LOG_STACK=daily` (not `single`) — see §6, log rotation.
   - `QUEUE_CONNECTION=database`, `DB_CONNECTION=mysql` with the real
     `1cal_api` database credentials.
   - Every real credential the deploying code path actually needs is
     present (Razorpay keys at minimum — payments are live functionality,
     not optional).

4. **Confirm the Supervisor worker config matches this repo's own copy.**
   `deploy/supervisor/onecallfix-worker.conf` is now the version-controlled
   source of truth (see that file's own header comment). If it was edited
   this deploy, install/update it on the server (§4 below) — don't let the
   server's copy silently drift from what's in git.

---

## 2. Deploy sequence

```bash
# On the server, as callf1207, inside /home/1callfix.com/public_html/api/

# 1. Maintenance mode — brief, and only really matters while a migration
#    is running. Given the pre-launch traffic level (see header), a short
#    window is low-risk today; treat this as non-optional once real
#    customer traffic exists. --secret lets you (and only you, via the
#    URL it prints) bypass the maintenance page to verify the deploy
#    before reopening to everyone.
php artisan down --secret="$(openssl rand -hex 16)" --render=errors::503

# 2. Pull the deployed code (or SCP it in, per PROJECT_HANDOFF.md §9's
#    documented alternative — SCP has been the more reliable path
#    historically; CyberPanel's File Manager zip-upload has a known bug
#    with symlinks under vendor/bin/*, avoid it).
git pull origin main
# -- or --
# scp -r <local-build-folder> callf1207@31.97.186.175:/home/1callfix.com/public_html/api/

# 3. PHP dependencies — production flags: no dev packages, optimized
#    class-map autoloader.
composer install --no-dev --optimize-autoloader

# 4. Frontend build — REQUIRED. resources/views/welcome.blade.php already
#    calls @vite(['resources/css/app.css', 'resources/js/app.js']); Vite's
#    compiled manifest (public/build/manifest.json) does not exist until
#    this runs, and public/build is git-ignored (never shipped via `git
#    pull`/SCP of source) -- skipping this step is not "the old CDN
#    version keeps working", it is a broken public "/" route
#    (ViteManifestNotFoundException) until this has run at least once on
#    THIS server. This is true independent of item 56 (admin panel's own
#    Tailwind CDN vs. compiled-build status, still unresolved as of this
#    writing) -- confirm Node/npm are installed on the server (this
#    session's own environment had neither, so this exact step was never
#    verified end-to-end here; verify it for real on first use).
npm install
npm run build

# 5. Database migrations. --force is required in production (Artisan
#    refuses to run migrations in a non-interactive production shell
#    without it) -- you already backed up in §1 step 1.
php artisan migrate --force

# 6. Production caches -- verified this session to run cleanly against
#    this exact codebase (config:cache does NOT fail on a .env-dependent
#    config closure; a common Laravel footgun, checked explicitly here,
#    not assumed).
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 7. Restart the queue worker so it picks up the new code -- a running
#    `queue:work` process has the OLD code loaded in memory and will NOT
#    see changes from a `git pull` until restarted.
sudo supervisorctl restart onecallfix-worker:*

# 8. OPcache -- if opcache.validate_timestamps=0 on this server (the
#    standard high-performance production setting; confirm the real value
#    via CyberPanel's PHP configuration screen, not assumed here), PHP
#    will keep serving the OLD compiled bytecode for changed files until
#    the PHP process is restarted/reloaded. Reload lsphp via CyberPanel's
#    PHP management UI, or `systemctl restart lsphp83` (confirm the exact
#    service name for this server's configured PHP version before
#    relying on that command blind).

# 9. Reopen to the public once §3 (post-deploy verification) passes.
php artisan up
```

---

## 3. Post-deploy verification

Do this BEFORE running `php artisan up` if you used maintenance mode with
`--secret` (use the secret URL to test while still in maintenance mode for
everyone else) — or immediately after, if you skipped maintenance mode for
a low-risk, no-migration deploy.

1. **Health check** — confirms app boot, database connectivity, AND queue
   connectivity in one request (extended this session; previously only
   confirmed the app process was alive):
   ```bash
   curl -s -o /dev/null -w "%{http_code}\n" https://api.1callfix.com/up
   ```
   Expect `200`. A `500` means the app booted but a real dependency (DB or
   queue) failed its check — do NOT reopen to the public; investigate via
   `storage/logs/laravel.log` first (the response body itself never
   contains the failure detail, by design — see `HealthCheckTest.php`).

2. **Public route smoke test** (confirms the Vite build from §2 step 4
   actually produced a working manifest):
   ```bash
   curl -s -o /dev/null -w "%{http_code}\n" https://api.1callfix.com/
   ```
   Expect `200`, not a 500 from a missing Vite manifest.

3. **Admin login smoke test** — log in at `https://api.1callfix.com/admin/login`
   with a real admin account. Confirms session auth, RBAC role-assignment
   lookup, and (as of this session) the new login rate limiter doesn't
   misfire on a normal login.

4. **One real booking flow**, using a disposable, clearly-marked test
   fixture — this repository's own git history already establishes this
   exact pattern (testing live against production with disposable
   fixtures, then cleaning up or reverting; see `PROJECT_CURRENT_STATE.md`
   §2): create one Service booking end-to-end through the admin panel
   (Bookings → New Booking), confirm it reaches `searching_provider` /
   dispatch correctly, then cancel/delete it as a test artifact — do not
   leave test bookings in the live operational funnel.

5. **Queue worker is actually running** the new code, not a stale process:
   ```bash
   sudo supervisorctl status onecallfix-worker:*
   ```
   Confirm `RUNNING` with a recent start time (matching your restart in
   §2 step 7, not an old uptime).

6. **Confirm `APP_DEBUG` is really off in practice**, not just in the
   `.env` file (config caching can theoretically diverge from the file if
   something is stale) — the same live test `KNOWN_RISKS_AND_DECISIONS.md`
   item 25 used:
   ```bash
   curl -s -H "Accept: application/json" https://api.1callfix.com/api/this-route-does-not-exist-deploy-check
   ```
   Expect a plain `{"message": "..."}` 404 with no `exception`/`file`/
   `line`/`trace` fields. If those fields appear, debug mode is leaking —
   treat as a rollback-now situation (see `ROLLBACK_PLAN.md`).

---

## 4. Installing/updating the Supervisor worker config

Only needed the first time, or when `deploy/supervisor/onecallfix-worker.conf`
changes in this repo:

```bash
sudo cp deploy/supervisor/onecallfix-worker.conf /etc/supervisor/conf.d/onecallfix-worker.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart onecallfix-worker:*
```

(Confirm the real Supervisor config directory on this specific
CyberPanel-managed server before the first real use — `/etc/supervisor/conf.d/`
is the standard Debian/Ubuntu location this file assumes, but CyberPanel
may manage Supervisor from a different path. Verify once, then this
runbook and that file's own header comment are the source of truth going
forward.)

---

## 5. Cache commands — why each one, and the failure mode they prevent

All four were verified this session to run cleanly against the current
codebase (a temporary, disposable `.env` with production-representative
values — never a real one — was used to test this locally; see this
session's own report for the exact method). Run them in this order, every
deploy, per §2 step 6:

- `config:cache` — the most common failure mode is a config file with a
  closure that calls an `env()` NOT already read once during normal
  bootstrap, or a helper that isn't safe to call before the full app is
  booted. Checked directly here, not assumed.
- `route:cache` — fails if any route uses a non-serializable closure
  instead of a controller reference. This codebase's routes are
  controller/Livewire-component-based, not closure-heavy — verified clean.
- `view:cache` — precompiles Blade views; fails on a real Blade syntax
  error that PHP itself wouldn't catch until first render. Cheap
  insurance to catch it here, in the deploy, not in front of a real user.
- `event:cache` — caches auto-discovered event/listener mappings.

If any of these ever fails on a future deploy, that is a real regression
to fix before proceeding — do not skip the failing command and continue.

---

## 6. Log rotation

Production `.env` must set `LOG_STACK=daily` (see `.env.example`'s own
comment on this). Laravel's `daily` driver rotates `storage/logs/laravel.log`
into dated files and prunes anything older than `LOG_DAILY_DAYS`
(`config/logging.php` defaults this to 14 days — an honest engineering
default, not a business decision). The default `single` driver (correct
for local dev, wrong for production) writes one file that grows forever.
Confirm after the first production deploy under `daily` that
`storage/logs/` actually shows dated files after a day passes, not one
ever-growing `laravel.log`.

---

## 7. OPcache

Required production PHP setting, enforced at the server/PHP-config level
(not something this Laravel app's own code can set) — confirm via
CyberPanel's PHP configuration screen for whichever PHP version is bound
to this vhost:

- `opcache.enable=1`
- `opcache.memory_consumption=128` (or higher, tune to real traffic once
  it exists — 128MB is a reasonable starting default, not a measured
  number for this specific app yet)
- `opcache.max_accelerated_files=10000`
- `opcache.validate_timestamps` — `1` (safe, checks file mtimes, small
  overhead) is the simpler choice until deploy volume is high; `0`
  (fastest, but requires an explicit OPcache reset/PHP reload on every
  deploy, per §2 step 8) is the standard high-traffic production setting.
  Pick one and make sure whoever deploys knows which — mismatching this
  assumption is exactly how a deploy "doesn't take effect" despite a
  successful `git pull`.

---

## 8. Known gaps this runbook cannot close (see the session's final report)

- Node/npm were not available in the session that wrote this runbook —
  §2 step 4's `npm run build` was never actually run/verified end-to-end
  against this real codebase. Verify it for real on first use, on a
  Node-capable machine or the server itself.
- Whether production's `public/build/` currently exists at all was not
  verifiable from this session (no server access) — if it does not, the
  public `/` route is likely already broken today, independent of any
  future deploy. Check this directly, soon.
- Backup automation status (§0) is unconfirmed, not merely undocumented.
