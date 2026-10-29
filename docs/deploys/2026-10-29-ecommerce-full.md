# Release 2026-10-29 — E-commerce Phases 1–5 + coupons (one deploy)

Use with `docs/DEPLOY_RUNBOOK.md`. This is the deploy sheet; what each part does is in its own note:
`2026-10-28-ecommerce-phase1-sellers.md`, `2026-10-29-ecommerce-phase2-storefront.md`,
`2026-10-29-ecommerce-phase3-delivery-returns.md`, `2026-10-29-ecommerce-phase4-commission.md`,
`2026-10-29-ecommerce-phase5-compliance.md`, `2026-10-29-ecommerce-coupons.md`.

Commerce stays OFF for customers (Admin → Modules) until the owner switches it on.

| | |
|---|---|
| Deploys | `main` at the merge commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `c512e1d` (E-commerce Phase 0, deployed 2026-10-29) — stop if the server shows anything else |
| Full test suite | see the merge commit; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | `composer.lock changed: no` |
| Front-end build (steps 6–7) | `yes` (seller portal, shop pages, rider Shop tab, admin screens) |
| Version | `VERSION` file bumped to `2026.10.29.4` |

## Step 4 → 5: migrate straight after the pull
The scheduler runs every minute; the new code reads the new tables. Run step 5 immediately after step 4.

## Step 5 — migrations that must be pending (exactly these five)
- `2026_10_28_200000_add_seller_approval_to_stores_table` (existing stores become `approved`)
- `2026_10_29_100000_create_marketplace_returns_table` (seeds `marketplace_returns.manage`, `marketplace_returns.refund`)
- `2026_10_29_200000_create_marketplace_commission_rules_table` (seeds `marketplace_commission.manage`)
- `2026_10_29_300000_add_shop_tax_compliance_columns`
- `2026_10_29_400000_add_marketplace_order_to_coupon_usages`

No enum column is widened. Rollback count for `migrate:rollback --step=`: `5`

## Step 10 — scheduled commands that must be listed
- `refunds:escalate-shop-returns` (every fifteen minutes) — new
- `marketplace:expire-unpaid`, `marketplace:release-earnings` — from Phase 0, still listed

## Test logins (owner runs on the server, after step 9)
`php artisan test-accounts create --password=<your password>` — adds `test.seller@1callfix.test` (an approved, open
"Test Shop" with one ₹100 product, `/seller/login`); `test.rider@1callfix.test` now also takes shop deliveries.

## Browser checks after deploy (Commerce still OFF)
- Admin: Stores (Approval column), Shop Settings, Shop Commission, Shop Returns, Shop Tax Report and Refund Controls
  (Shop return refunds) open; Coupons form shows "For: Service bookings / Shop orders"; Modules: Commerce inactive.
- `/seller/login` with the test seller → Home shows Test Shop; Products lists Test Product.
- `/<city>/shop` → 404 (Commerce off) — correct.
- Partner page: "Seller or shop" still goes to the waiting list (seller sign-ups are off until you turn them on).

## Admin settings to do after deploy (owner)
1. Shop Settings → Blocked words (medicine, alcohol, tobacco, …) and Minimum rider payout.
2. Refund Controls → Shop return refunds: franchise / HQ limits, second-approval threshold, escalation hours.
3. Roles: give `marketplace_returns.*` / `marketplace_commission.manage` to the roles that should have them.
4. With the CA: Shop Settings → Tax compliance (TCS %, TDS %, grievance officer).
5. When ready to recruit: Shop Settings → Take seller sign-ups = On.
6. Walk-through before launch: switch Commerce on for ONE test franchise only, run the order → rider → delivery
   → return flow with the test logins, then switch it off again.
