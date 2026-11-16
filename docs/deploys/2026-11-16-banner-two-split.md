# Release 2026-11-16 (2) — two hero banners split the row

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `a6b72c53` |
| Full test suite | 4923 of 4924; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (new width classes) |
| Version | `VERSION` file bumped to `2026.11.16.2` |

## What's in it
- Homepage hero banners: exactly two banners now split the row in half on tablets and larger (they took 30% each and
  left 40% empty). Three or more keep the peeking layout; phones unchanged (one banner, next one peeking).

## Step 5 — migrations that must be pending (exactly these)
- none

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Computer: the two hero banners fill the row side by side, no empty gap on the right.

## Admin settings to do after deploy (owner)
- none
