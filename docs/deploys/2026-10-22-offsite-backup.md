# Release 2026-10-22 — off-site backup to Google Drive

Use with `docs/DEPLOY_RUNBOOK.md`, then `docs/DISASTER_RECOVERY.md` part A.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `e1e429c` |
| Full test suite | not affected: no PHP, migration or front-end change (one shell script + docs) |
| Composer | composer.lock changed: no |
| Front-end build (steps 6–7) | no |

## What's in it
- `scripts/backup-offsite.sh`: hourly encrypted DB dump to Google Drive (48 h), daily copy of the nightly dump
  (30 days + monthly for 400 days), daily `storage/app` sync with 30-day archive, encrypted `.env` copy,
  healthchecks.io ping on success/failure.
- `docs/DISASTER_RECOVERY.md`: one-time setup, monthly drill, restore while the server is alive, restore after
  the VPS dies (Hostinger snapshot + Drive, or a new server).

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: `0`

## Step 8 / 9
Not strictly needed (no PHP change); run them anyway if you follow the runbook in full. Harmless.

## Step 10 — scheduled commands that must be listed
- none new in `schedule:list` (the backup runs from the user crontab, set in DISASTER_RECOVERY A6)

## After deploy
- `docs/DISASTER_RECOVERY.md` A1–A6 (rclone crypt remote, healthchecks, `.env` keys, first runs, crontab).
- Save the rclone crypt password + salt and a copy of `.env` in the password manager.
