# Release 2026-10-25 — separate dispatch queue

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `0c4e21e` |
| Also ships | `7e8a2e2` fix(partner): sign-up prefills name and mobile from the /partners application (another session; PHP + test only) |
| Full test suite | 4038 of 4039 pass. Only failure: the known `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local `.env`) |
| Composer | composer.lock changed: no |
| Front-end build (steps 6–7) | no |
| Version | `VERSION` file bumped to `2026.10.25.1` |

## What's in it
- Job offers get their own queue. Every dispatch round (ServiceMatchingJob,
  BundleConsolidationJob, ConsolidationOfferTimeoutJob, Parcel/Taxi/Marketplace
  dispatch jobs, including each job's own re-dispatch) and the offer PUSH
  (ProviderJobOfferNotification, RiderParcelOfferNotification) go to the queue
  named by `DISPATCH_QUEUE`. Offer mail stays on the normal queue.
- `DISPATCH_QUEUE` unset = no change at all (jobs keep no explicit queue).
  The code can go live first; the switch is the .env line below.
- `deploy/supervisor/onecallfix-worker.conf` is now one group
  `onecallfix-worker` with two programs: `onecallfix-default` (command
  unchanged) and `onecallfix-dispatch` (`--queue=dispatch --sleep=1`, ONE
  process — rounds must stay one at a time). `restart onecallfix-worker:*`
  still restarts both.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 9 — worker switch (root), INSTEAD of the plain restart, in this order
1. Look first: `cat /etc/supervisord.d/onecallfix-worker.ini` and
   `grep -c '^DB_QUEUE=' /home/1callfix.com/public_html/.env` (must be 0;
   if not, stop: the default worker's queue name differs).
2. Back up and install:
   `cp /etc/supervisord.d/onecallfix-worker.ini ~/onecallfix-worker.ini.bak-20261025`
   `cp /home/1callfix.com/public_html/deploy/supervisor/onecallfix-worker.conf /etc/supervisord.d/onecallfix-worker.ini`
3. `supervisorctl reread && supervisorctl update && supervisorctl status | grep onecallfix`
   — both `onecallfix-worker:onecallfix-default_00` and
   `onecallfix-worker:onecallfix-dispatch_00` RUNNING.
4. Only now, as the deploy user: add `DISPATCH_QUEUE=dispatch` to `.env`,
   `php artisan config:clear && php artisan config:cache`.
5. Root: `supervisorctl restart onecallfix-worker:*` and status again.

Rollback: remove the `DISPATCH_QUEUE` line, `config:clear && config:cache`,
wait until `SELECT COUNT(*) FROM jobs WHERE queue='dispatch'` is 0, then put
the backed-up .ini back, `reread && update`.

## Step 10 — scheduled commands that must be listed
- none new

## Checks after deploy
- `SELECT queue, COUNT(*) FROM jobs GROUP BY queue;` — nothing piling up on `dispatch`.
- `tail -n 20 storage/logs/worker-dispatch.log` shows ServiceMatchingJob lines after a test booking.
- Test booking: providers get the offer/ring as before.

## Admin settings to do after deploy (owner)
- none
