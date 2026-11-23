# Release 2026-11-23.5 — Field associates: who signed up which shop, progress bars, monthly rewards

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `47a58cd` (2026.11.23.4) |
| Full test suite | passed 5233 of 5233 (local, 2026-11-23); known failures: none |
| Composer | no |
| Migrations | 1 (below), additive (2 new tables, 1 nullable column, 1 permission), no enum widened |
| Front-end build (steps 6–7) | **yes** (new colours on the progress bars and the associate page) |
| Scheduled commands | none new |
| Queue worker restart (step 9) | yes (usual) |
| Version | `VERSION` 2026.11.23.5 |

## What's in it (plain words)
Owner 2026-11-23: field business associates visit restaurants and shops and add them (details, KYC, products); the
app tracks which associate added which shop so incentives can be paid. Owner's plan: a small reward after KYC
approval, another after the first completed order.

- **Admin → Users & Partners → Field associates** (permission `field_associates.manage`; Super Admin always has it):
  - **Rewards** (Super Admin only): ₹ when a shop goes live, ₹ on its first completed order, monthly target (shops
    live, 0 = none). A reward is saved with the amount at the time it is earned, so changing the amount never
    rewrites a month already paid.
  - **Add an associate**: name + mobile + city team. They get a short code (FA001, FA002, ...). A new number gets a
    customer account with no password; the first sign-in sets one with a code to their phone.
  - Each associate: this month's signed up / live / first orders / **To pay**, the target bar, **See shops** (every
    shop's progress bar), **Pause / Resume**, "Forgot the link? Add a shop they signed up" (shops from the last 60
    days with no associate) and "Not theirs? Remove" (removes its rewards too). Every change is audit-logged.
  - **Download <month> sheet**: one row per associate with what to pay (CSV, opens in Excel). This / last / the month
    before.
- **The associate's page**: Account → **My field work** (`/account/field-work`): month card with the target bar and
  rewards, a big **QR** + "Send link on WhatsApp" + "Copy link", and **My shops** with a 5-step coloured progress bar
  each: Signed up → Products (Menu) added → KYC approved → Shop live 💰 → First order 💰, plus "Next: ..." in plain words.
- **How a shop gets credited**: the owner scans the associate's QR (or opens their link `/fa/FA001`). It opens
  /partners with "Seller or shop" picked; the sign-up form shows "Helped by field associate Ravi (FA001)" and the new
  shop or restaurant is credited. Opening the link signs out whoever is signed in on that phone, so the shop always
  gets the owner's own account (the associate can also fill the form on their own phone). One link visit credits one
  shop.
- **Decisions (mine, owner said "decision is yours")**:
  - Reward 1 is paid when the shop goes **live** = KYC approved **and** the shop approved (an admin can approve a shop
    only after its KYC). KYC alone pays nothing, so a shop whose KYC passed but which never opens costs nothing.
  - Reward 2 = the shop's first **completed** order from a real customer. Orders by the shop's own owner or by the
    associate never count. Each reward is paid once per shop.
  - An associate is never credited for their own shop; a paused associate's link credits nothing.
  - **No money moves in the app**: the sheet is paid by bank / UPI outside the app, so the manual money approval
    model does not apply. Recording payouts inside the app would need that model (separate release if wanted).

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_23_400000_create_field_associates` (tables `field_associates`, `field_associate_rewards`; column
  `stores.field_associate_id`; permission `field_associates.manage` given to super_admin)

Rollback count for `migrate:rollback --step=`: 1

## Step 10 — scheduled commands that must be listed
- none new

## Test login and walk-through (THUMB RULE: test login per module)
`php artisan test-accounts create --password=...` now also makes **test.associate@1callfix.test** (Test Field
Associate, FA code shown on its page, credited with the Test Shop and Test Restaurant). Signs in at `/login`, then
Account → My field work. `test-accounts disable` pauses it.

Walk-through:
1. Super Admin: Admin → Field associates → set the two rewards (e.g. ₹200 / ₹300) and a target → Save.
2. As test.associate: `/login` → Account → My field work: code, QR, the test shop and restaurant with their bars.
3. On another phone (or a private window): scan the QR → /partners shows "Seller or shop" → fill name / mobile →
   sign-up shows "Helped by field associate Test Field Associate (FA..)". Finish → the shop appears on the associate's
   page at "Signed up".
4. Admin: approve its documents, then the shop (Stores) → associate page shows "Shop live" and the reward.
5. Admin → Field associates → Download the month sheet.

## Browser checks after deploy
- Admin menu → Users & Partners → **Field associates** opens; Rewards card shows (blank = 0).
- `/fa/XYZ` (unknown code) opens /partners normally.
- `/account/field-work` signed out → customer sign-in; signed in as a normal customer → "This page is for 1CallFix
  field associates".

## Admin settings to do after deploy (owner)
- Field associates → Rewards: set both amounts **before** associates start (a reward earned while an amount is 0 is
  saved as ₹0).
- Add each associate (name + their own mobile + city team). Give a franchise admin the permission
  `field_associates.manage` (Roles) if they should manage their city's associates.
