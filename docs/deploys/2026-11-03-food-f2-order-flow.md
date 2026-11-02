# Release 2026-11-03 — Food F2: restaurant order flow (Food stays OFF)

Use with `docs/DEPLOY_RUNBOOK.md`. Deploy together with F1 (`2026-11-03-food-f1-restaurants.md`): one walk-through.
Customer food pages come in F3, so Food stays OFF in Admin → Modules; the flow is walked through with the test logins.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Branch | `feature/food-f1-restaurants` (F1 `a24dbdc` + F2) |
| Live before | `<hash currently on the server>` |
| Full test suite | F1 full run 4563/4564 (known `.env` failure); F2: Food 23, Marketplace 172, Parcel 246, Dispatch 153, Payments 155, CustomerWeb 528, Operations 100, Coupons 170, Earnings 143, Journey 79, Housekeeping 14 all pass. Run the full suite again at merge |
| Composer | no |
| Front-end build (steps 6–7) | yes (seller orders and rider screens changed) |
| Version | bump `VERSION` at merge time |

## What's in it
- Checkout for a restaurant refuses: no FSSAI number, outside opening hours, a dish "sold out today", a choice that
  breaks its rule ("Chutney: pick 1"), or an add-on not on that dish. Dishes are never counted as stock.
- Charges, all from Admin → Food Settings (blank = off):
  - packaging charge (the restaurant's own, never above the Super Admin limit);
  - delivery: radius in km, and a distance fee (first km fee + per-km after). Blank = the restaurant's flat fee;
    free delivery above an amount still applies to online orders only (thumb rule);
  - GST on food under section 9(5): charged on food + packaging, kept by the platform (new
    `marketplace_settlements.platform_gst_amount`), never paid to the restaurant. The restaurant's own tax % is not
    used for food. Rate blank until the CA confirms it.
- The restaurant must accept within **Food Settings → Time to accept** (10 min). The clock starts when the order is
  placed (cash, wallet) or when the online payment arrives. Missed = cancelled as the restaurant's doing: no fee, full
  refund (`food:expire-unaccepted`, every minute).
- Accepting asks "Ready in __ min" (pre-filled with its cooking time + busy minutes) and promises a ready time,
  shown on the order. Buttons read Accept → Start cooking → Food is packed and ready. Chosen add-ons show on each
  dish line (shops too).
- A rider can be called before the food is ready: **Food Settings → Call a rider this long before the food is
  ready** (`food:call-riders`, every minute). Blank = the rider is called when the food is ready, as for shops. A
  rider who takes it while it cooks sees "The food is cooking, ready about 7:45 PM" and can only deliver once the
  restaurant marks it ready; a rider waiting at a restaurant gets no other offers.
- A customer can cancel a food order only before the restaurant accepts (shops unchanged).

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_03_100000_add_restaurant_fields_for_food` (F1)
- `2026_11_03_110000_add_food_order_flow_fields`

Rollback count for `migrate:rollback --step=`: 2

## Step 10 — scheduled commands that must be listed
- `food:expire-unaccepted` (every minute)
- `food:call-riders` (every minute)

## Walk-through (test logins, Food ON only for the test franchise or not at all)
1. Admin → Food Settings: the new groups Delivery and Tax show; nothing to set yet.
2. As `test.restaurant`: Orders shows "Accept by …" on a new order; accept with a time; Start cooking; Food is ready.
3. Leave one test order unaccepted for 10 minutes: it is cancelled and refunded, reason "did not accept the order
   within 10 minutes".

## Admin settings to do after deploy (owner)
- Food Settings → Tax: GST rate after the CA confirms (usually 5%).
- Food Settings → Delivery: radius and distance fee, or leave blank to use each restaurant's flat fee.
- Food Settings → Kitchen timing: "Call a rider … before ready" (suggest 10 minutes) once riders are signed up.
