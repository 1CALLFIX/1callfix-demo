# Release 2026-11-06 — Partner app and Rider app, one account

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `f12f7da` |
| Full test suite | 4523 passed of 4524; known failures only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` |
| Composer | no |
| Front-end build (steps 6–7) | yes (one new page) |
| Version | `VERSION` 2026.11.06.1 |

## Owner rules (2026-11-06)
- Three apps, one account (one phone number). **Rider app:** every delivery (parcel, food, shop). **Partner app:**
  service partners, shops, restaurants. Each role still joins through /partners with its own documents and approval.
- One person may do both. He may accept a service job while delivering; while a service job is **in progress**
  (start code given) he gets no delivery offers.
- Rider app and Partner app keep separate earnings and statements (unchanged).
- A restaurant or shop owner may deliver his own orders. There are no rider bonuses today; any future rider bonus
  must skip the rider's own shop's or restaurant's orders.

## What's in it
- **Each app keeps its own notifications** (new table `user_push_tokens`, one token per account per app). Plumbing
  offers ring on the Partner app, deliveries on the Rider app, booking updates on the Customer app. An alert never
  goes to the wrong app. Browsers (web push) keep `users.fcm_token` as before.
- **Buttons open the other app:** inside the Partner app, "Open the Rider app"; inside the Rider app, "Open the
  Partner app" (its Play Store page, which shows "Open" when installed). Browsers still switch dashboards.
- **Wrong-app guard:** a Partner app page opened in the Rider app (or the other way) shows "This is in the 1CallFix
  … app" with a button, instead of mixing the screens.
- **No delivery offers while a service job is in progress** (parcel, shop/food delivery, shop return pickups).
- Android shell (next app build only, not needed for this deploy): a Play Store link to one of our own apps opens
  that app directly when it is installed.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_06_100000_create_user_push_tokens_table`

Rollback count for `migrate:rollback --step=`: 1

## Step 10 — scheduled commands that must be listed
- none new

## After deploy
- People already using the Rider or Partner app: their app re-saves its notification token the next time it is
  opened (automatic). Until then their alerts go where they went before.

## Browser checks after deploy
- `test.provider@1callfix.test` in a browser: the partner menu still shows "Switch to rider mode" / "Deliver parcels too".
- In the Partner app (phone), a partner who is also a rider: the menu shows "Open the Rider app".
- In the Rider app, open a partner link: "This is in the 1CallFix Partner app" page.
- Tail of storage/logs/laravel.log: no new errors.

## Admin settings to do after deploy (owner)
- none
