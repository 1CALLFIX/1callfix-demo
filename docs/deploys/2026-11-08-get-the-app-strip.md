# Release 2026-11-08 — "Get the app" strip on phones

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `cde0976` |
| Full test suite | 4622 passed of 4623 (after updating RateAppLinkTest for the new Play link); known failure only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (new resources/js/app-banner.js) |
| Version | `VERSION` file bumped to `2026.11.08.2` |

## What's in it
- Slim strip above the header on phone browsers only: Android → "Get the app" (Play Store, com.call.customer);
  iPhone/iPad → "Tap Share, then Add to Home Screen" (no iOS app). Never on computers, inside the Android app,
  or once the website is installed. ✕ hides it on that phone for N days.
- Super Admin → Homepage & sign-up → "Get the app" strip: on/off (default on), days hidden after closing (default 14).
- Words: Website text → "Get the app strip (phones)", en + te.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Phone Chrome (Android): strip with "Get the app" opens the Play Store page; ✕ hides it.
- iPhone Safari: strip with the Add to Home Screen line; computer: no strip.

## Admin settings to do after deploy (owner)
- None required.
