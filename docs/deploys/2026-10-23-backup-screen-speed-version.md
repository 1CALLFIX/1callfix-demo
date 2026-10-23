# Release 2026-10-23 (2) — Backup & Restore opens instantly, version label, Clear Data hidden on live

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `5445a80` |
| Full test suite | 3954 of 3955 pass. Only failure: the known `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local `.env`) |
| Composer | composer.lock changed: no |
| Front-end build (steps 6–7) | **yes** (new classes in the side menu / footer version label) |
| Version | `VERSION` file = `2026.10.23.2` (first release that shows it) |

## What's in it
- **Backup & Restore opened slowly** because every page load listed the encrypted Google Drive three times (seconds
  each), and a failed listing was never remembered. Now: one listing for all folders, kept for 15 minutes, a failure
  kept for 1 minute, and a new scheduled `backups:refresh-catalog` (every 5 minutes) keeps it ready. If the list is
  not ready the page still opens at once and shows "Checking the off-site backups…" while it loads.
- **Version label** `v2026.10.23.2 · <commit>` on the admin, customer and partner login screens, at the bottom of the
  admin side menu (every admin page and dashboard), the partner side menu and pages, and the site footer. The commit
  part is read from the server's git checkout at step 8 (`config:cache`), so it always matches what is deployed.
- **System > Clear Data** is a test/staging tool: the screen refuses to open in production (404, by design since
  2026-09-07). Its menu link is now hidden on the live site.

## Step 5 — migrations that must be pending (exactly these)
- none (skip both commands)

Rollback count for `migrate:rollback --step=`: `0`

## Step 10 — scheduled commands that must be listed
- `backups:refresh-catalog` (every 5 minutes)

Extra check after step 10 (as callf1207):
```bash
cd /home/1callfix.com/public_html && php artisan backups:refresh-catalog
```
✅ `<n> backups listed.` (a red error line means rclone cannot reach the Drive: send the output).

## Browser checks after deploy
- System menu: **Clear Data** is gone; **Backup & Restore** opens in about a second with **Healthy** and the backup
  times filled.
- Bottom of the admin side menu: `v2026.10.23.2 · <the hash you deployed>`.
- Admin login, customer login (`/login`), partner login (`/provider/login`) and the site footer show the same label.

## Admin settings to do after deploy (owner)
- None.
