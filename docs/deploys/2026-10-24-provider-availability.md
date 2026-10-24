# Release 2026-10-24 — provider availability truth

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `ba5d908` |
| Full test suite | 4031 of 4032 pass. Only failure: the known `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local `.env`) |
| Composer | composer.lock changed: no |
| Front-end build (steps 6–7) | yes (`resources/js/provider-alerts.js`) |
| Version | `VERSION` file bumped to `2026.10.24.2` |

## What's in it
- Provider dashboard "Will you get jobs?" panel: a location older than
  `provider.location_stale_after_minutes` (default 30) is now a blocking red
  item, matching what dispatch already enforces. It used to say "eligible".
- "Refresh location" button on the provider dashboard while online.
- Location heartbeat now calls `refreshLocation`, which only re-stamps the fix
  while the provider is still online (row-locked) and never flips an offline
  provider back online. No activity-log row per heartbeat any more.
- When a locked / backgrounded phone is opened again, one location fix is sent
  at once instead of waiting up to 2 minutes.
- Offer watcher poll uses `wire:poll.6s.keep-alive`, so a hidden tab keeps
  checking for offers.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new (`providers:expire-stale-online` already listed)

## Browser checks after deploy
- /provider as a provider: online → "Refresh location" and "Go offline" show;
  panel green with a fresh fix.
- Lock the phone > 30 min (or wait), unlock: panel turns green again within a
  few seconds without tapping anything, unless the provider was already set
  offline (then "Go online" shows, and the provider stays offline).
- No console errors on /provider and /provider/jobs.

## Admin settings to do after deploy (owner)
- none. Prod already has `notifications.channels = mail,push` and FCM set.
- Ask each provider to tap "Go online" and "Enable job alerts" once on their phone.
