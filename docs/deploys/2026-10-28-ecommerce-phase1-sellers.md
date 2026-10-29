# Release 2026-10-28 — E-commerce Phase 1 (sellers)

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `c512e1d` (E-commerce Phase 0) — check `git log -1` on the server; if Phase 0 is not live yet, deploy `2026-10-28-ecommerce-phase0.md` first |
| Full test suite | see the merge commit; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | `composer.lock changed: no` |
| Front-end build (steps 6–7) | `yes` (new seller portal views) |
| Version | `VERSION` file bumped to `2026.10.28.2` |

## What's in it
Commerce stays OFF for customers. This release lets sellers join and get ready before it opens.
- **Join (one path):** /partners → "Seller or shop" → the one partner sign-up (OTP, shop name, shop address and pin,
  documents) when Super Admin turns on Shop Settings → "Take seller sign-ups". Off (default) = waiting list. A
  customer or a service partner opens a shop with the same account; one shop per account.
- **Approval:** the shop waits in Admin → Stores ("Waiting" badge, banner, filter). Approve only after the seller's
  documents are approved (Providers → KYC); "Not approve" needs a note the seller sees. Customers never see or buy
  from a shop that is not approved and active (API + checkout), nor from a closed shop.
- **Seller portal `/seller`** (sign-in `/seller/login`, Forgot password works): Home (status, open/close, today's
  numbers, low stock), Orders (accept → packing → ready; pickup hand-over only with the customer's collection code;
  "Can't fill this order" refunds in full with no fee, until ready; new-order bell + push/SMS), Products (add/edit,
  photos, stock, discount, hide; blocked words refused; new products and changed names/photos wait for approval
  while that switch is on), Shop (description, logo, phone, address, pin, minimum order, delivery fee, free delivery
  above), Earnings (in wallet, on hold until the return window ends, commission owed), Payment Accounts and
  Request Payout (partner screens reused).
- **Admin → Shop Settings** (Super Admin, audit-logged, global/franchise/zone): seller sign-ups, product approval,
  blocked words, unpaid-order expiry, return window, rider minimum payout and who funds it, rider offer rounds.
  Replaces the tinker step from Phase 0.
- Link rule: store and product slugs are now clean (`lakshmi-general-stores`, `-2` on collision). Existing slugs
  are not changed.
- The partner mode switch now covers three roles (partner / rider / my shop).

## Step 5 — migrations that must be pending (exactly these)
- `2026_10_28_200000_add_seller_approval_to_stores_table` (existing stores become `approved`)

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- none new (Phase 0's `marketplace:expire-unpaid` and `marketplace:release-earnings` stay)

## Test logins (owner runs on the server)
`php artisan test-accounts create --password=<your password>` now also makes:
- `test.seller@1callfix.test` — seller with an approved, open "Test Shop" and one ₹100 product (`/seller/login`)
- `test.rider@1callfix.test` — now also takes shop deliveries (`commerce_delivery_rider`)

## Browser checks after deploy
- Admin → Shop Settings opens (Super Admin); Admin → Stores shows the Approval column; Admin → Modules: Commerce
  still inactive.
- `/seller/login` with the test seller → Home shows "Test Shop" → Products lists "Test Product" → add a product
  with a photo → it shows "Awaiting approval" → Admin → Products approve it.
- /partners with seller sign-ups OFF: "Seller or shop" goes to the waiting list.

## Admin settings to do after deploy (owner)
- Shop Settings → Blocked words (one per line: medicine, alcohol, tobacco, ...) before turning seller sign-ups on.
- Shop Settings → Minimum rider payout before Commerce goes ON.
- Shop Settings → Take seller sign-ups = On when ready to recruit sellers.
