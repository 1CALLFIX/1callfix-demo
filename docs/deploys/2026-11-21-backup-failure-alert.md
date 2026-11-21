# Release 2026-11-21.3 — off-site backup failure alert

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `f6a59ed` (2026.11.21.2 Careers page) |
| Full test suite | 5084/5084 passed (2026-11-21, final code), no known failures |
| Composer | no (`composer.lock` unchanged) |
| Front-end build (steps 6–7) | no (PHP, one Blade text change, no new CSS classes) |
| Version | `VERSION` 2026.11.21.3 |

## What's in it
### Why
The Google Drive that holds the off-site backups filled up on 29 Oct 2026. `scripts/backup-offsite.sh` then failed
every hour (581 times) until 21 Nov, and nobody inside the app was told. The owner freed the Drive on 21 Nov; backups
work again.

### Backup failure alert
- New scheduled command `backups:check-offsite`, every 15 minutes. It reads the list of copies that actually reached
  the Drive (the same cached list as System → Backup & Restore) and alerts when:
  - the newest **hourly** copy is older than 150 minutes (two runs missed; one blip stays quiet), or
  - the newest **daily** copy is older than 28 hours, or
  - the Drive cannot be read at all.
- Who: everyone who can open Backup & Restore (`backups.restore`; Super Admin always). **Email** (on by default,
  switch "Off-site backup late or failing" on Admin → Alert Emails) and **push** to admins who switched on ops alerts.
- The message says the cause in plain words, from the backup log: "the Google Drive is full", "the Drive sign-in
  expired", or "the log shows no error, check the cron".
- Repeats every 6 hours while still broken; one "Backup is working again" message after the fix.
- Settings (optional, `.env`): `BACKUP_ALERT_AFTER_MINUTES` (150), `BACKUP_DAILY_ALERT_AFTER_HOURS` (28),
  `BACKUP_ALERT_REPEAT_HOURS` (6), `BACKUP_CLOCK_TIMEZONE` (Asia/Kolkata).

### Fix: Backup & Restore showed "Healthy" 5.5 hours too long
The backup file names carry the server clock (India time) but the app runs in UTC, so each copy looked 5.5 hours
newer than it was. Times are now read in India time; the screen's "Last copy" line says "(India time)".

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- `backups:check-offsite` (every 15 minutes)

## Browser checks after deploy
- `php artisan backups:check-offsite` prints "Off-site backups are arriving on time."
- System → Backup & Restore: "Healthy", and "Last copy" matches the newest hourly copy in India time.
- Admin → Alert Emails: "Off-site backup late or failing (and when it works again)" is listed and ticked.

## Admin settings to do after deploy (owner)
- Keep 1–2 GB free on the backup Google account (Drive, Gmail and Photos share its 15 GB).
- Check that healthchecks.io emails (the second alert, which also covers a dead server) are not going to spam.
