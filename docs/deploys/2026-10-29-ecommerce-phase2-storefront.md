# Release 2026-10-29 — E-commerce Phase 2 (customer storefront)

Use with `docs/DEPLOY_RUNBOOK.md`. Deploy after `2026-10-28-ecommerce-phase1-sellers.md` (this branch carries it).

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | Phase 1 (or Phase 0 `c512e1d` if Phase 1 ships together with this) — check `git log -1` on the server |
| Full test suite | see the merge commit; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | `composer.lock changed: no` |
| Front-end build (steps 6–7) | `yes` (new shop views) |
| Version | `VERSION` file bumped to `2026.10.29` |

## What's in it
Owner rule: our own 1CallFix design, not a copy of any marketplace — every item shows the local shop it comes from,
the shop's delivery terms are said up front, and every order has a code at hand-over.
- **Public, city-prefixed pages** (link rule): `/{city}/shop` (search, categories, shops open now, items),
  `/{city}/shop/{shop}` (shop page with rating, minimum order, delivery fee, free-delivery-online note),
  `/{city}/shop/{shop}/{product}` (photos, stock, Product schema for search). All 404 while Commerce is off for
  that city; only approved, active shops and approved products. Header menu shows "Shop" where it is on.
- **Bag** `/shop/bag`: one block per shop, steppers capped at stock and the limit per order, minimum-order hint,
  removed/unsold items flagged.
- **Checkout** `/shop/checkout/{shop}`: delivery to a saved address (own address, same city only — checked on the
  server) or pickup; online (Razorpay), wallet, or cash. Thumb rules: discounted items → cash disabled and refused;
  free delivery only for online/wallet (cash pays the fee). The customer pays today's price (old bag prices are
  re-priced at checkout).
- **Shop orders** `/shop/orders` and order page: live progress, the code for the rider (once assigned) or the shop
  (when ready), Pay now for an unpaid online order, cancel (free before the shop accepts; the admin cancellation
  policy after; not once packing starts), one rating per completed order (shown on the shop page).
- Coupons are still refused on shop orders; they are the next step (the coupon engine is booking-only today).

## Step 5 — migrations that must be pending (exactly these)
- none new in this phase (Phase 1's `2026_10_28_200000_add_seller_approval_to_stores_table` if shipped together)

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy (Commerce still OFF: expect 404)
- `/<city>/shop` → 404 while Commerce is off. To walk through before launch, switch Commerce on for ONE test
  franchise only (Admin → Modules), then: test customer → `/<city>/shop` → Test Shop → add Test Product →
  bag → checkout pickup + cash → seller portal accepts → packs → ready → customer page shows the code → seller hands
  over with it → customer rates. Switch Commerce off again afterwards.

## Admin settings to do after deploy (owner)
- none new (see Phase 1's list)
