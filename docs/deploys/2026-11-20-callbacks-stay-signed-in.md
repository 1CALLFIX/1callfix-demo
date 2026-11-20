# Release 2026-11-20 — Call-back requests easy to find; apps stay signed in

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `1046e42` (2026.11.19.4 post packs, checked on the server 2026-11-20) |
| Full test suite | 5034/5035 passed (2026-11-20, final code); known failure only: NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default (.env) |
| Composer | no (`composer.lock` unchanged) |
| Front-end build (steps 6–7) | yes (new classes on Partner leads: the amber call-back box and its buttons) |
| Version | `VERSION` 2026.11.20.1 |

## What's in it
### 1. "Help me join" call-backs (owner 2026-11-20)
- A partner who taps "Help me join" on Go live was saved on their old lead row, which kept its first "Applied" date,
  so the request sat weeks down the Partner leads list with no way to call from the page.
- Admin → Partner leads now opens with an amber **📞 Asked for a call (N)** box: every open request (status new), newest
  first, with when they asked, a big **📞 Call** button (tel:), **WhatsApp** (wa.me click-to-chat, nothing is sent by the
  server) and the status picker. Changing the status (handed off, converted, ...) takes it out of the box.
- Every mobile number in the list is a tap-to-call link.
- A service partner becoming a delivery rider is filed as **Parcel Delivery**, not Service: the call is about the part
  still waiting for approval, else the newest one.

### 2. Apps stay signed in after they are closed (owner 2026-11-20)
- The session lasts 2 hours, and no sign-in used "remember me", so reopening the Partner app later showed the
  "1CallFix Partner" chooser (signed out).
- Signing in **inside the 1CallFix apps** (customer, rider, partner: user agent `1CallFixApp`) now remembers the person,
  like any phone app. A normal browser is unchanged. Signing out still signs out.
- People already signed in keep the old 2-hour session until they sign in once more.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Admin → Partner leads: the amber box shows "Best AC" (9059729821), asked 20 Nov 6:43 AM, with Call and WhatsApp.
- Partner app on the phone: sign out, sign in again, close the app (swipe it away), open it after a few hours: still
  signed in, not the chooser.

## Admin settings to do after deploy (owner)
- none for this release. Separate decision pending: jobs for providers with the app closed (Settings →
  `provider.location_stale_after_minutes`, now 30).
