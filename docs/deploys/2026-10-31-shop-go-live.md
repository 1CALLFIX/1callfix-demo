# Release 2026-10-31 (2) — Shop go-live: rider return pickup, shop pages in Telugu

Use with `docs/DEPLOY_RUNBOOK.md`. This release sits on `main` and also carries the super-app shell `838fbb5`
(pushed earlier, never deployed; release note `2026-10-31-super-app-shell.md`). Deploy them together: read that
note as well (its browser checks too). Home v3 is NOT in this release (owner 2026-10-31: not required).
Commerce stays OFF for customers until the owner switches it on (Admin → Modules).

| | |
|---|---|
| Deploys | `main` at the merge commit that adds this file |
| Live before | `5c23234` (Test shop) — stop if the server shows anything else |
| Full test suite | see the merge commit; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | `composer.lock changed: no` |
| Front-end build (steps 6–7) | **yes** (super-app shell needs it; new rider/seller/customer return-pickup screens) |
| Version | `VERSION` file bumped to `2026.10.31.2` |

## What's in it
- **Rider return pickup.** Owner decision 2026-10-31: when an admin accepts a return on a delivery order, a rider
  collects it from the customer and takes it back to the shop. The **seller pays the rider's fee** out of the order's
  held earnings; the customer pays nothing. If the seller's held share is smaller than the fee, the platform covers
  the rest (shown on the return as "paid by platform").
  - Shop Settings → Delivery → **Return pickup: rider fee** (₹). **Blank = off**: returns are marked received by hand,
    exactly as today.
  - Rider → Shop deliveries lists open pickups in the rider's zone (within the zone's dispatch radius of the
    customer); first to accept takes it. The customer reads a **pickup code** from their order page; the shop reads
    a **drop code** from Seller → Orders → Returns. The drop marks the return received; the refund then goes through
    the approval model as before. The rider's fee goes to their wallet at the drop (once only).
  - A rider on a pickup is busy: no parcel or shop delivery offers until it is dropped.
  - Until a rider takes it, the seller (or an admin) can still mark the item received by hand; the pickup is
    withdrawn with no fee. Once a rider has it, only the drop code (or an admin) closes it.
  - Admin → Shop Returns shows the pickup state under each return.
- **Every customer word on the shop pages is in Website text (English + Telugu).** 183 texts in three new groups on
  Admin → Growth → Website text & language: *Shop*, *Shop checkout*, *Shop orders & returns*. English wording is
  unchanged. Telugu defaults were written by Claude — **a native reader must check them** before Telugu is switched
  on. Seller, rider and admin screens stay English (as before). Error messages that come from deeper checks (stock,
  minimum order, payment) are still English only.
- Not in this release (owner decision 2026-10-31): demand-based (peak-hour) commission. Launch uses the existing
  Shop Commission rules (shop / category / franchise / city).

## Step 4 → 5: migrate straight after the pull
The scheduler runs every minute; run step 5 immediately after step 4.

## Step 5 — migrations that must be pending (exactly these)
- `2026_10_31_200000_add_rider_pickup_to_marketplace_returns` (new columns on `marketplace_returns`, all nullable)

No enum column is widened. Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- none new (`refunds:escalate-shop-returns`, `marketplace:expire-unpaid`, `marketplace:release-earnings` still listed)

## Test logins (owner runs on the server, after step 9)
`php artisan test-accounts create --password=<your password>` (re-running resets the test shop):
- `test.customer@1callfix.test` — places the shop order and asks for the return.
- `test.seller@1callfix.test` (`/seller/login`) — Test Shop with 8 test products; accepts, packs, gives the drop code.
- `test.rider@1callfix.test` (`/rider`) — delivers the order, then collects the return.
- Super Admin (your own login) — accepts the return and approves the refund.

## Browser checks after deploy (Commerce still OFF)
- Admin → Shop Settings shows **Return pickup: rider fee** (blank).
- Admin → Website text & language shows the groups *Shop*, *Shop checkout*, *Shop orders & returns*.
- `/<city>/shop` → 404 (Commerce off) — correct.
- `/rider/shop` with the test rider opens ("No shop deliveries right now").

## GO-LIVE CHECKLIST (owner, in this order)
1. **Shop Settings** (Admin → Shop Settings): blocked words; minimum rider payout; **return pickup rider fee**;
   return window (3 days); unpaid-order expiry.
2. **Refund Controls → Shop return refunds**: franchise limit, HQ limit, second-approval threshold, escalation hours.
   Until these are set, nobody below Super Admin can approve a shop refund.
3. **Roles**: give `marketplace_returns.manage`, `marketplace_returns.refund`, `marketplace_commission.manage` to the
   roles that should have them.
4. **Shop Commission**: check the default rate per category for the pilot city.
5. **Tax** (with the CA): Shop Settings → TCS %, TDS %, grievance officer; each seller enters their own GSTIN / PAN. Unset rates = nothing withheld.
6. **Telugu**: a native reader checks the three shop groups (download the sheet, fix, upload) before Telugu goes on.
7. **Riders**: at least 2 approved riders online in the pilot zone (parcel riders take shop jobs).
8. **Sellers**: Shop Settings → Take seller sign-ups = On; approve the first shops; each shop sets hours, delivery
   fee, minimum order and uploads product photos.
9. **Walk-through** with the test logins, Commerce ON for ONE test franchise only:
   order (online, wallet, cash) → seller accepts/packs → rider delivers with the code → customer asks a return
   with a photo → admin accepts → rider collects (pickup code) → shop takes it (drop code) → refund requested and
   approved → check the seller's held earnings dropped by the refund share and the rider fee. Then
   `marketplace:release-earnings` after the window pays the rest. Switch Commerce OFF again.
10. **Go live**: Commerce ON for the pilot city only. Watch Admin → Shop Returns and the laravel.log for the first days.
11. Before public launch: `php artisan test-accounts disable`.
