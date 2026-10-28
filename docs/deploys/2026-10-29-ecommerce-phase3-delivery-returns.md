# Release 2026-10-29 — E-commerce Phase 3 (shop deliveries on the rider web, returns)

Use with `docs/DEPLOY_RUNBOOK.md`. This branch carries Phases 1 and 2; deploy them together or in order.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | check `git log -1` on the server (Phase 0 `c512e1d`, or later) |
| Full test suite | see the merge commit; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | `composer.lock changed: no` |
| Front-end build (steps 6–7) | `yes` |
| Version | `VERSION` file bumped to `2026.10.29.1` |

## What's in it
- **Shop deliveries on the rider web** (plan D4): parcel riders are offered shop deliveries too; one job at a time
  across parcel and shop; riders with a stale location (rider web closed) get no shop offers, same as parcel.
  Rider → Shop: offers (accept / decline; the ring banner fires on every rider page), the job (shop, then customer,
  directions and call, cash to collect) and delivery with the customer's code. Rider Earnings shows shop cash to
  hand back; "Settle from wallet" covers parcel and shop.
- **Returns** (owner decision: within the return window, default 3 days; damaged or wrong item only):
  customer asks on the order page with 1–3 photos and the items → Admin → Shop Returns accepts (sets the refund,
  at most the order total) or declines with a note → seller (Seller → Orders → Returns) or admin confirms the item
  is back → refund under the MANUAL MONEY ACTIONS model: permission `marketplace_returns.refund` (deciding is
  `marketplace_returns.manage`; both Super Admin only by default, assignable in Roles), franchise / HQ limits,
  second approver above a threshold, escalation, reason required, audit log, idempotent.
  Online orders refund to the original payment (Razorpay); wallet and cash orders to the wallet.
  The seller is not paid while a return is open; the refund comes out of the order's held money — seller,
  platform and franchise shares cut in proportion (a cash-at-pickup seller owes their part back).

## Step 5 — migrations that must be pending (exactly these)
- `2026_10_29_100000_create_marketplace_returns_table` (also seeds the two permissions)
- plus Phase 1's `2026_10_28_200000_add_seller_approval_to_stores_table` if Phase 1 ships together

Rollback count for `migrate:rollback --step=`: `1` (or `2` with Phase 1)

## Step 10 — scheduled commands that must be listed
- `refunds:escalate-shop-returns` (every fifteen minutes)

## Browser checks after deploy
- Admin → Shop Returns opens (empty); Admin → Refund Controls shows "Shop return refunds (₹)".
- Rider web: the "Shop" tab appears only once Commerce is on somewhere.

## Admin settings to do after deploy (owner)
- Refund Controls → Shop return refunds: franchise limit, HQ limit (blank = that level cannot approve, so only
  Super Admin can), second-approval threshold, escalation hours.
- Roles: give `marketplace_returns.manage` / `marketplace_returns.refund` to the roles that should handle returns.
