# Release 2026-10-25 — E-commerce Phase 0 (shop money fixes)

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `ba5d908` |
| Full test suite | see the merge commit / PR; known failures only: `NotificationCenterAuditTest` (.env); the membership date test is fixed on main (`0c4e21e`) |
| Composer | `composer.lock changed: no` |
| Front-end build (steps 6–7) | `no` (no views or JS changed) |
| Version | `VERSION` file bumped to `2026.10.25.1` |

## What's in it
The Commerce module stays OFF. This release only makes shop orders safe for when it is switched on.
- Online shop orders are held until the Razorpay capture webhook marks them paid; the seller cannot accept an
  unpaid order. New `POST /api/marketplace-orders/{id}/pay`. Unpaid orders expire (stock returned).
- Thumb rule: a discounted order (product discount) is a benefit and can never be paid in cash. Coupons are
  refused on shop orders until the coupon engine is connected (Phase 2).
- New `marketplace_settlements`: goods money → seller after the return window (default 3 days), delivery fee →
  rider at delivery (they were paid nothing before), franchise share on both. Cash on delivery: the rider owes
  back everything except their own share; cash at pickup: the seller owes the commission. Owed cash is
  recovered from the wallet and blocks payouts until covered. A rider at `parcel.rider_cash_due_limit` gets no
  cash shop orders.
- Minimum rider payout: on a free-delivery order (or any order whose fee share is below it) the rider still gets
  `marketplace.rider_min_payout`. The gap comes out of the platform commission by default
  (`marketplace.rider_min_payout_funded_by` = platform), or out of the seller's share when set to `seller`.
  Pickup orders have no rider, so no rider pay and no delivery commission.

## Step 5 — migrations that must be pending (exactly these)
- `2026_10_25_100000_create_marketplace_settlements_table`

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- `marketplace:expire-unpaid` (every five minutes)
- `marketplace:release-earnings` (hourly)

## Browser checks after deploy
- Admin → Marketplace Orders opens; Admin → Modules still shows Commerce inactive.

## Admin settings to do after deploy (owner)
- Before Commerce is switched ON: `marketplace.rider_min_payout` (unset = no minimum, so riders earn nothing on
  free-delivery orders). Funding defaults to the platform. There is no screen for it yet (Phase 1 seller/admin
  screens); until then it is set with tinker.
- Optional: `marketplace.return_window_days` (default 3), `marketplace.unpaid_expiry_minutes`
  (default 30), `parcel.rider_cash_due_limit` (applies to shop cash too).
