# Release 2026-11-01 — Parcel walk-through fixes

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `d4eed30` (Parcel Stage 2) |
| Full test suite | 4337 passed of 4338; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | no (local build gives the same `app-BifjUQta.css` as live) |
| Version | `VERSION` file bumped to `2026.11.01.1` |

## What's in it
Found in the live walk-through of Parcel Stage 2 (2026-11-01, parcel NLR-PCL-0111-00000001):
- A sender blocked from parcels now sees "Sending parcels is not available on your account" as soon as they open
  `/parcel`, instead of filling the whole form first.
- The rider distance line no longer says "ago ago".

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: `0`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- `/parcel` as a blocked test customer: the message, no form. After Allow: the form.

## Admin settings to do after deploy (owner)
- none
