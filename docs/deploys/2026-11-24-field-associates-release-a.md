# Release 2026-11-24.1 — Field associates release A: owner's incentive plan, cheat-proof rules, sign up on the associate's phone

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `e6e647d` (2026.11.23.5) |
| Full test suite | passed 5241 of 5241 (local, 2026-11-24); known failures: none |
| Composer | no |
| Migrations | 1 (below), additive (2 new tables, 2 nullable columns, settings written only where empty or 0), no enum widened |
| Front-end build (steps 6–7) | **yes** (new colours on the associate page and the admin screen) |
| Scheduled commands | **1 new**: `field-associates:sync` daily 00:20 |
| Queue worker restart (step 9) | yes (usual) |
| Version | `VERSION` 2026.11.24.1 |

## What's in it (plain words)
Owner 2026-11-24: the incentive plan, and six review points (test data, refunds, credit changes, signing up without
logging out, earned vs paid, restaurants).

- **Owner's plan, option 2 (owner 2026-11-24): fixed pay ₹8,000 + petrol ₹2,500 a month, paid outside the app,
  plus these incentives per shop or restaurant** (Super Admin can change every amount on Admin → Field associates):
  - ✅ Live (KYC approved + shop approved): **₹50**
  - 🛍️ 10 products / menu items **approved** (and the shop is live): **₹50**
  - 🎉 First genuine order, completed and paid: **₹100**
  - At most **₹200** per shop or restaurant.
  - 🏆 Monthly bonus, **highest level reached, not added together**: 10 shops ₹500, 20 shops ₹1,000, 30 shops ₹1,500, 40 shops ₹2,500 (four rows on the Rewards card).
    A shop counts in the month it became live **with** 10 approved products (the first order is not needed for the
    bonus: customer demand is outside the associate's control).
  - The migration writes these amounts only where Super Admin has not set an amount yet (empty or 0).
- **A first order counts only when** (anti-cheating):
  - it is completed and paid (cash paid at pickup counts), and at least the minimum order value (**₹100**, setting);
  - it is not placed by the shop owner, the associate, or (for a real associate) a test login;
  - the customer was not already the first order of another of this associate's shops (stops one friend ordering at
    every shop);
  - the **return window + 1 day has passed** (currently 3 + 1 = 4 days); the reward is earned on that day;
  - a fully refunded order cancels its reward (audit-logged); the shop's next genuine order can earn it instead.
- **Test data never mixes with real pay**: a test shop (owner is an `@1callfix.test` login) earns only for a test
  associate and the other way round; test associates show a "Test" badge and are left out of the monthly sheet.
- **Changing credit** (point 3): adding a shop, moving it to another associate, or removing it needs a **reason**; each
  shop has a **Credit history** (who, when, why, from → to; the sign-up itself is the first line). Moving keeps the
  rewards with the shop. If the shop has rewards from an earlier month (may already be paid), only Super Admin can
  change it. Two associates can never hold one shop (one column, re-checked under a lock).
- **Sign up on the associate's phone without logging out** (point 4): Account → My field work → **🏪 Sign up a shop
  now** / **🍽️ Sign up a restaurant now**. The form shows "Helped by field associate …". The **owner's** mobile gets
  the code (the associate's own number is refused), so the account is the owner's. No password and no documents
  here: the done screen has **"Send the owner their link on WhatsApp"**; the owner signs in on their own phone with a
  code and uploads their own KYC. The associate stays signed in. The QR / link is still there ("Or the owner signs
  up on their own phone"); the old "fill on this phone (you will be signed out)" link is gone.
- **Restaurants** (point 6): the same rewards; shops 🏪 and restaurants 🍽️ are counted apart on the associate's
  page, the admin screen and the sheet.
- **Earned is not paid** (point 5, part 1): the page says "Earned … paid by the office after they check it"; the sheet
  has a `status` column "earned, not yet approved or paid" and new columns (shops / restaurants signed up and live,
  10 products, first orders, complete for bonus, bonus, total earned). Approved → payable → paid with the manual
  money approval model is **release B** (not in this release).
- **Progress bar** steps: Signed up → 10 products 💰 (shows "4 approved so far") → KYC approved → Live 💰 → First order 💰.
  The top bar shows progress to the next bonus ("7 of 10 shops for the ₹500 bonus").

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_24_100000_field_associates_products_bonus_history` (columns `field_associate_rewards.order_id`,
  `.customer_id`; tables `field_associate_bonuses`, `field_associate_credit_changes`; the plan's settings where empty)

Rollback count for `migrate:rollback --step=`: 1

## Step 10 — scheduled commands that must be listed
- `field-associates:sync` (daily 00:20)

## Test login and walk-through (THUMB RULE: test login per module)
Same login as before: **test.associate@1callfix.test** (`php artisan test-accounts create --password=...`), credited
with the Test Shop and Test Restaurant.

1. Super Admin: Admin → Field associates → Rewards shows ₹50 / ₹50 / ₹100, at most ₹200, first order from ₹100, bonus
   10 → ₹500, 20 → ₹1,000, 30 → ₹1,500, 40 → ₹2,500 (or the amounts you set before).
2. As test.associate: `/login` → Account → My field work → **Sign up a shop now** → type a second phone's number →
   that phone gets the code → fill name, shop name, address → Submit. You stay signed in; "Send the owner their link
   on WhatsApp" shows. The new shop is on your page at "Signed up".
3. Admin → Field associates → See shops → the new shop has "Credit history (1)". Try "Not theirs? Move or remove"
   without a reason (refused), then with one.
4. Download the month sheet: test associates are not on it.

## Browser checks after deploy
- Admin → Field associates opens; the Rewards card shows the amounts and four bonus rows.
- `/account/field-work/add/shop` signed in as a normal customer → "This page is for 1CallFix field associates".
- `/fa/XYZ` still opens /partners normally.

## Admin settings to do after deploy (owner)
- Check the amounts on the Rewards card (the plan is filled in only where nothing was set).
- Fixed pay ₹8,000 and petrol ₹2,500 are paid outside the app (payroll); the app shows and sheets incentives only.
