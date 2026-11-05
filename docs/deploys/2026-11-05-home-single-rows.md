# Release 2026-11-05 (3) — homepage: doors first, one row per section

Use with `docs/DEPLOY_RUNBOOK.md`. Sits on top of `2026-11-05-home-modules-order.md` (live at 37be0f5).

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `37be0f5` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | yes (new classes for the one-row layout) |
| Version | `VERSION` 2026.11.05.3 |

## What's in it
- Phone top bar shows only the logo (the name was cut to "1Call..."). The name still shows from tablet width up.
- **Explore 1CallFix** is now the very first section; the top banner comes right under it.
- Line under Explore names every business: home services, shops, food delivery and parcel delivery
  (unless a different line was saved in Website text & language, `home.promise`).
- **Parcel & delivery** card is back, after the services, shop and food cards.
- Every section is ONE row that scrolls sideways — category icons ("Home services at your doorstep"), the icons
  inside each business card, why-choose-us, membership plans and Google reviews. No second rows.
- Most booked, New & noteworthy, Offers and category rows use small tiles: picture, name, price only
  (no category label, instructions or time). About three fit across a phone.
- **New & noteworthy** drifts slowly left and right on its own; it stops as soon as the visitor touches it,
  and never moves on phones set to reduce motion. Super Admin switch: Homepage sections → under Page order.
- The line under Explore is editable (English + Telugu) on Admin → Growth → Homepage sections, "Explore 1CallFix"
  doors box (also Website text & language, `home.promise`). Empty = built-in words. Super Admin only.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_05_120000_home_doors_first_parcel_card_back` (settings only: order doors → top banner → …, Parcel card on at place 39)

Rollback count for `migrate:rollback --step=`: 1 (its `down()` does nothing; old values are in the step-2 backup)

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy (phone)
- Top bar: logo only, no "1Call..." text.
- First section is Explore 1CallFix (Services, Parcel, Shop, Food), then the top banner.
- Most booked: small tiles, name and price only, sideways scroll.
- Business cards: services, Shop near you, Food near you, then Parcel & delivery.
- Home services at your doorstep: one row of icons, scrolls sideways.
- New & noteworthy: small tiles moving slowly; touching stops it.

## Admin settings to do after deploy (owner)
- If the line under Explore still says the old words, an old copy was saved: Admin → Growth → Homepage sections →
  Explore box → clear the English and Telugu boxes (or type new words) → Save.
