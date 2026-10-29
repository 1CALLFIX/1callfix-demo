# Release 2026-10-29 — E-commerce: coupons on shop orders

Use with `docs/DEPLOY_RUNBOOK.md`. Carries the earlier e-commerce phases if they are not live yet (see their notes).

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | check `git log -1` on the server |
| Full test suite | see the merge commit; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | yes |
| Version | `VERSION` file bumped to `2026.10.29.4` |

## What's in it
- The coupon engine is connected for `commerce` (`config/coupons.php`). Admin → Coupons has a "For" choice:
  Service bookings or Shop orders (a shop coupon has no service targets; city / global targets still apply).
- Checkout has a coupon box; the discount shows in the summary. Thumb rule: coupons are online / wallet only —
  cash is refused on the server, and switching to cash removes the coupon.
- Lifecycle: reserved with the order (re-validated under the coupon lock), confirmed when the order completes,
  released when it is cancelled (customer, seller, admin, unpaid expiry).
- HQ-funded: the seller and franchise earn on the full price; the platform carries the discount out of its share.
  GST on the invoice is on the price after the coupon.
- Coupons are still governed by the existing switches (`coupons.enabled`, `coupons.unpaid_hold_minutes`).

## Step 5 — migrations that must be pending (exactly these)
- `2026_10_29_400000_add_marketplace_order_to_coupon_usages`
- plus the earlier e-commerce migrations if they ship together

## Step 10 — scheduled commands that must be listed
- none new

## Admin settings to do after deploy (owner)
- To run a shop coupon: Admin → Coupons → new coupon "For: Shop orders" → activate (existing approval step).
