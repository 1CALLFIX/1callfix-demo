# Release 2026-11-03 — Food F1: restaurants (set-up only, Food stays OFF)

Use with `docs/DEPLOY_RUNBOOK.md`. Recommended: do not deploy F1 on its own; ship it together with F2 (order flow)
so the first Food deploy is one walk-through. It is safe on its own (Food stays OFF; commerce shops are unchanged).

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Branch | `feature/food-f1-restaurants`, built on `feature/shop-checkout-polish` (`b4e2fbf`), which must be deployed first or with it |
| Live before | `<hash currently on the server>` |
| Full test suite | 4564 run folder by folder, 4563 pass; the 1 failure is the known `.env`-dependent `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` |
| Composer | no |
| Front-end build (steps 6–7) | yes (new Blade classes: seller restaurant page, add-ons page, veg mark) |
| Version | bump `VERSION` at merge time (the checkout-polish release also bumps it) |

## What's in it
- A restaurant is a seller whose store has module `food`. It uses the same `/seller` portal; the menu reads
  Home · Orders · Menu · Add-ons · Restaurant · Earnings.
- Restaurant page: FSSAI licence (14 digits), up to 3 cuisines from the Super Admin list, cost for two, usual
  cooking time, packaging charge (only when Super Admin sets a highest amount), opening hours per weekday (up to 3
  slots a day, past midnight allowed; no hours = open whenever switched on).
- Home: FSSAI warning, "outside opening hours" warning, Busy buttons (+10, +20 … min, switch off by themselves).
- Menu: veg / egg / non-veg mark (required), "Sold out today" (back by itself at the restaurant's midnight), add-on
  choice groups per dish. Food words blocked for the shop never block a restaurant.
- Add-ons page: choice groups with a rule ("Pick 1", "Optional, up to 3") and priced options; removed options are
  soft-deleted so old orders keep them.
- Admin → Food Settings (Super Admin only, audited, global / franchise / zone): FSSAI needed, cuisine list,
  packaging charge limit, default cooking time, busy limit, busy auto-off hours, accept time (10 min, used in F2).
- Admin → Stores: a restaurant without FSSAI cannot be approved while Food Settings needs it.
- Test login: `test.restaurant@1callfix.test` (`php artisan test-accounts create --password=...`), an approved,
  open "Test Restaurant" with 6 dishes and 2 choice groups. Disabled by `test-accounts disable`.

Not yet (F2): orders do not check hours, sold-out, add-on rules or packaging; the customer food pages do not exist.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_03_100000_add_restaurant_fields_for_food`

Rollback count for `migrate:rollback --step=`: 1

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- `/admin/food-settings` opens for Super Admin, shows 3 groups (Restaurants, Kitchen timing, Orders).
- `/admin/shop-settings` still opens and saves.
- Sign in as `test.restaurant@1callfix.test` at `/seller/login`: menu shows Menu / Add-ons / Restaurant; dishes show
  veg/non-veg marks; "Test Sold Out Dish" shows Sold out today.
- Sign in as `test.seller@1callfix.test`: the shop portal looks exactly as before (Products / Shop, no FSSAI).

## Admin settings to do after deploy (owner)
- Admin → Food Settings: check the cuisine list; set "Highest packaging charge" if restaurants may charge one.
- Do NOT switch Food on in Admin → Modules yet (customer pages come in F3).
