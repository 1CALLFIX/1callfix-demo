# Release 2026-11-04 — Sticky Add / Buy now bar on phones

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `a831167` (VERSION 2026.11.04.2) |
| Full test suite | 2026-11-04, serial run: 4485 tests, all pass except the known `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env, unrelated) |
| Composer | no |
| Migrations | none |
| Front-end build (steps 6–7) | **yes** (new classes for the sticky bar) |
| Worker restart (step 9) | yes |
| Version | `2026.11.04.3` |

## What's in it
- Product page on a phone: once the main Add / Buy now buttons scroll off screen, a bar sticks to the bottom with
  the same actions (a quantity stepper and View cart once the item is in the cart).
- The floating cart bar steps aside while the sticky bar shows.
- No sticky bar when the shop is closed or the item is sold out.

## Step 5 — migrations
- none

## Step 10 — scheduled commands
- none new

## Test logins
- No new module; use the existing customer test login.

## Browser checks after deploy
- Phone width → any test-shop product: scroll down past the buttons; the bar appears; Add puts it in the cart and the
  bar turns into the stepper + View cart; Buy now goes to checkout.
- A sold-out item or a closed shop: no sticky bar.
