# Release 2026-11-05: website support for the Android apps

Use with `docs/DEPLOY_RUNBOOK.md`. The Android apps themselves are built on the build PC (see `mobile/README.md`);
this release is only what the live site needs so the apps work.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `637ce53` (VERSION 2026.11.04.5) |
| Full test suite | see the commit message |
| Composer | no |
| Migrations | none |
| Front-end build (steps 6–7) | **yes** (`resources/js/native-app.js`, native Google sign-in in `customer-auth.js`) |
| Worker restart (step 9) | **yes** (push sending changed: queued notifications) |
| Version | `2026.11.05.1` |

## What's in it
- `/app/partner`: start page of the 1CallFix Partner app. A signed-in service partner goes to /provider, a shop
  or restaurant to /seller, a rider to /rider; anyone else sees two sign-in choices and "Join as a partner".
- Push: job and delivery offers carry `kind=offer` (the apps ring them loudly), and FCM messages ask Android
  for high priority. Web push is unchanged.
- `/push/token`: when a different person signs in on the same phone, the previous account stops getting that
  phone's notifications.
- Every partner/customer layout has a `cf-signed-in` meta tag for the app script. In a normal browser the
  script does nothing.
- Google sign-in inside the app uses the phone's Google account; in a browser it is unchanged (pop-up).

## Step 5: migrations
- none

## Step 10: scheduled commands
- none new

## Test logins
- Existing `test.provider`, `test.rider`, `test.seller`, `test.restaurant`.

## Browser checks after deploy
- /app/partner signed out: two sign-in cards and Join as a partner.
- /login → Continue with Google still works in Chrome (pop-up).
- Provider job offer push still arrives in Chrome (web push unchanged).
