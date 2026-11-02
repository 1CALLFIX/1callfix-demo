# Release 2026-11-03 — Food F5: Food Dashboard, restaurant sign-up, and the Food release (F1–F5 together)

Use with `docs/DEPLOY_RUNBOOK.md`. **This is the one deploy note for Food**: F1–F5 go out together, after (or with)
shop Phase A. Read the F1–F4 notes for the detail of each part:
`2026-11-03-food-f1-restaurants.md`, `-f2-order-flow.md`, `-f3-customer-pages.md`, `-f4-problems-refunds.md`.
Food stays **OFF** for customers until the owner switches it on in Admin → Modules.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Branch | `feature/food-f1-restaurants` (F1–F5, on top of `feature/shop-phase-a` 47a71a7) |
| Live before | `<hash currently on the server>` |
| Full test suite | run folder by folder before push (see the result in the merge commit / report) |
| Composer | no |
| Front-end build (steps 6–7) | yes |
| Version | bump `VERSION` at merge time |

## What F5 adds
- Admin → Other Verticals → **Food Dashboard** (permission `marketplace_orders.view`, own franchise/zone only):
  food orders today (done / open / cancelled), orders the restaurant did not accept in time, orders waiting for a
  restaurant to accept (with the deadline), orders past their ready time, restaurants open / busy / without FSSAI,
  open food problems, this month's food refunds split restaurant / platform, riders with strikes.
- **Restaurant sign-up** through /partners → the one partner sign-up (owner rule: one join path). The "Restaurant"
  card opens when Super Admin turns on **Food Settings → Restaurants → Take restaurant sign-ups on /partners**
  (default off = waiting list, as before). The sign-up asks for the restaurant name, address, documents and the
  14-digit **FSSAI number** (required while "FSSAI licence needed" is on). The restaurant waits at approval in
  Admin → Stores (Food); its owner signs in at /seller to add the menu and hours. One store per account.

## Step 5 — migrations (all of Food, plus Phase A if not live)
`2026_11_02_200000_add_choices_and_units_to_products` (Phase A, only if not live yet),
`2026_11_03_100000_add_restaurant_fields_for_food`, `2026_11_03_110000_add_food_order_flow_fields`,
`2026_11_03_120000_add_food_issue_fields_to_marketplace_returns`. Nothing else may be pending.

## Step 10 — scheduled commands
`food:expire-unaccepted` and `food:call-riders` (every minute, from F2) must be listed in `schedule:list`.

## Test login
`php artisan test-accounts` creates `test.restaurant` (Test Restaurant, approved, with test dishes; signs in at
`/seller/login`). The owner passes the password on the command line on the server. `test-accounts disable` before
public launch.

## Full walk-through (Food ON only for the test franchise)
1. Admin → Modules: Food on for the test franchise. Food Settings: read every group once; set GST % only after the
   CA confirms; Problems & refunds defaults are fine.
2. `/{city}/food`: Test Restaurant is listed. Its menu: veg/egg/non-veg marks, sold-out dish hidden, Test Masala Dosa
   asks "Chutney: Pick 1".
3. As a test customer: add dishes, pay online (or cash without any benefit). As `test.restaurant` at /seller →
   Orders: accept with a cooking time within 10 minutes. Mark Preparing → Ready. The test rider delivers.
4. Let an order sit without accepting: after 10 minutes it is cancelled and refunded in full; Food Dashboard
   "Not accepted in time" shows 1.
5. Customer order page: Report a problem (Item missing, photo). Admin → Returns & food problems → Food problems:
   pick "Restaurant's fault", accept, request refund. Customer sees the refund; Test Restaurant → Orders → Problems
   shows what came out of its earnings. Food Dashboard shows the refund split.
6. Food Settings → Take restaurant sign-ups ON. /partners → Restaurant → sign up with a spare number and a 14-digit
   FSSAI number → Admin → Stores (Food) shows it pending. Reject or delete it after the test; turn sign-ups off again
   if not recruiting yet.
7. Switch Food off for the franchise again if it is not going live: `/{city}/food` returns 404.

## Admin settings after deploy (owner)
- Food Settings → Take restaurant sign-ups (on when ready to recruit).
- Food Settings → GST on food (after the CA confirms the rate).
- Refund Controls → Shop return refunds: franchise / HQ limits (blank = only Super Admin approves food refunds).
- Admin → Homepage sections → Food door (picture optional); Site text → Food (read the Telugu once).
