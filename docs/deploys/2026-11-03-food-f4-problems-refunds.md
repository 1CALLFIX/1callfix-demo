# Release 2026-11-03 — Food F4: problems with a food order and refunds (Food stays OFF until the owner switches it on)

Use with `docs/DEPLOY_RUNBOOK.md`. Deploy together with F1–F3 (one walk-through).

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Branch | `feature/food-f1-restaurants` (F1–F4, on top of shop Phase A `47a71a7`) |
| Live before | `<hash currently on the server>` |
| Full test suite | F4: Food 48, Marketplace 191, CustomerWeb 528, Seo 137, Settings 76, Coupons 170, Payments 155, Journey 79, Cancellation 130, Rbac 122 all pass. Run the full suite again at merge |
| Composer | no |
| Front-end build (steps 6–7) | yes |
| Version | bump `VERSION` at merge time |

## What's in it
- Customer order page (food, delivered): **Report a problem** within the Food Settings window (default 120 minutes):
  Item missing / Wrong item / Spilled or damaged / Poor quality, which dish and how many, a few words, photos
  (needed unless Food Settings turns that off). Status and refund show on the same page. All words in Site text → Food.
- Nothing comes back: an accepted problem goes straight to the refund.
- Admin → **Returns & food problems** (was "Shop Returns"; new tab "Food problems (open)"). Accepting a food problem
  needs **whose fault**:
  - Restaurant's fault — the restaurant pays its share (default 100%) out of that order's held earnings, never more
    than those; the platform pays the rest.
  - Damaged on the way — the platform pays (restaurant share default 0%); the rider gets a **strike**, never a
    deduction. Shown next to the rider's name ("2 strike(s) in 30 days"). Not offered for a pickup order.
  - Not clear — the platform pays (restaurant share default 0%). No strike.
- The refund is the existing approval model (permission `marketplace_returns.refund`, limits
  `refund.shop_return.*`, maker-checker, escalation): online orders back to the payment, wallet/cash orders to the
  wallet. Same Refund Controls as shop returns.
- The restaurant is paid when the problem window ends (minutes, not the shop's return days), never while a problem
  is open. The franchise share is never cut by a food refund.
- Restaurant portal → Orders → **Problems** tab: each problem, the fault and what came out of its earnings.

## Step 5 — migrations
New: `2026_11_03_120000_add_food_issue_fields_to_marketplace_returns` (fault, field_worker_id, restaurant_paid,
platform_paid). Pending for F1–F4 together: `2026_11_03_100000`, `2026_11_03_110000`, `2026_11_03_120000` (plus Phase
A's `2026_11_02_200000` if Phase A is not live yet).

## Step 10 — scheduled commands
None new (the existing shop return escalation command covers food problems).

## Walk-through (test logins; Food ON only for the test franchise)
1. Place and complete a Test Restaurant delivery order (customer → `test.restaurant` accepts / ready → test rider
   delivers).
2. Customer order page: Report a problem → Item missing, 1 meal, a photo → "Problem reported. We are looking at it."
3. Admin → Returns & food problems → Food problems: the row shows the rider with "0 strike(s)". Decide → pick
   "Restaurant's fault", note → Accept problem. Request refund (and approve as a second admin if above the
   maker-checker amount).
4. Customer page: "Refunded ₹… to your wallet". `test.restaurant` → Orders → Problems: "Taken from this order's
   earnings: ₹…".
5. Repeat with "Damaged on the way": the restaurant's earnings stay the same, the rider shows 1 strike.

## Admin settings after deploy (owner)
Food Settings → **Problems & refunds**: report window (120 min), photo needed (on), restaurant's share per fault
(100 / 0 / 0 %), strike period (30 days). Refund Controls → Shop return refunds: franchise / HQ limits must be set or
only Super Admin can approve.
