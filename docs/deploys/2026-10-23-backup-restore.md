# Release 2026-10-23 — Backup & Restore screen + Deleted items

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `67a212a` (or `8ae71f9` if the docs commit was pulled) |
| Full test suite | 3948 of 3949 pass. Only failure: the known `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local `.env`) |
| Composer | composer.lock changed: no |
| Front-end build (steps 6–7) | **yes** (new admin screen uses new Tailwind classes) |

## What's in it
- **System > Backup & Restore** (permission `backups.restore`, Super Admin always): backup status (Healthy / Late),
  Back up now, **Deleted items** (restore any deleted franchise, country, city, zone, category, subcategory, service,
  provider, store, banner), **Restore one** (franchise / city / store / provider from an hourly / daily / monthly
  backup: compare with now, bring back missing records; never overwrites newer data, never writes money tables;
  safety copy first; reason + audit log). See `docs/DISASTER_RECOVERY.md` section F.
- Deleting a country, city, zone, category or subcategory now archives it (was a permanent delete). Category and
  subcategory icons are kept so a restore brings them back. Every such delete is in the audit log. Each of the six
  screens links to Deleted items.

## Step 5 — migrations that must be pending (exactly these)
- `2026_10_23_100000_add_soft_deletes_to_geography_and_catalog`
- `2026_10_23_100100_create_data_restores_table`
- `2026_10_23_100200_seed_backups_restore_permission`

Rollback count for `migrate:rollback --step=`: `3`

## Before step 8 (server `.env`, as callf1207)
```bash
cd /home/1callfix.com/public_html && ! grep -q '^RESTORE_DB_DATABASE=' .env && cp -p .env ~/backups/env-before-restore-screen-$(date +%Y%m%d-%H%M%S) && printf '\n# Backup & Restore screen\nBACKUP_RCLONE_CONFIG=/home/1callfix.com/.config/rclone/rclone.conf\nRESTORE_DB_DATABASE=1cal_restore\n' >> .env && grep -E '^(BACKUP_RCLONE_CONFIG|RESTORE_DB_DATABASE)=' .env
```
✅ Both lines printed once. Step 8's `config:cache` then picks them up; step 9 restarts the worker that runs the jobs.

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- System > **Backup & Restore**: "Data backup" shows the last copy time and **Healthy**; the backup-time list is filled.
- **Back up now** → after about a minute the line under it says `Hourly offsite backup OK`.
- **Deleted items** tab opens (likely empty).
- **Restore one**: choose a franchise and the newest backup → **Compare with now** → within a minute a result
  (probably "0 missing"). Do not press Restore unless something is actually missing.
- Geography / Zones / Franchises / Categories / Subcategories / Services each show "Deleted items: restore →".

## Admin settings to do after deploy (owner)
- None. To let another role use the screen: Roles & Permissions → `backups.restore`.
