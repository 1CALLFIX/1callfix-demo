# Release 2026-11-03 — Food fixes from the live walk-through

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `b2cdda7` (VERSION 2026.11.03.5) |
| Full test suite | 2026-11-03, folder by folder: 4463 tests, all pass except the known `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env, unrelated) |
| Composer | no |
| Migrations | none |
| Front-end build (steps 6–7) | no (only existing CSS classes used) |
| Worker restart (step 9) | **yes** — the rider search runs in the queue worker |
| Version | `2026.11.03.6` |

## What's in it (found in the owner's live walk-through with the Test Restaurant)
1. **Food orders could never find a rider.** Food was offered only to "food delivery riders", and nothing in the app
   ever makes a rider one (riders join as parcel riders). Food now goes to food, shop and parcel riders — the same
   "same bike" rule as shop orders. Grocery and pharmacy riders are still separate.
2. **Ticked extras were lost.** In the dish pop-up, "pick several" groups (e.g. biryani extras) did not reach the cart
   (Livewire treats a checkbox bound to a missing key as on/off, not a list); a third tick said "not available".
   Each such group now opens as an empty list; at its limit the other choices are greyed out; a new pick clears the
   red message.
3. **"Shop" words on food orders.** New Site text (English + Telugu, editable in Site text → Food):
   "Sent to the restaurant", "Being cooked", "Restaurant accepted", "Note for the restaurant",
   "Show your code at the restaurant".

## Step 10 — scheduled commands
- none new

## Browser checks after deploy
- Test Restaurant → Test Chicken Biryani → tick 2 extras: the price adds them, the third is greyed out; the cart line
  shows "+ Boiled egg, Extra raita".
- A ready food order with the test rider online: the rider gets the offer.
