# Release 2026-11-05 — homepage: every business on top, smaller doors

Use with `docs/DEPLOY_RUNBOOK.md`. Sits on top of the Android app support release (`2026-11-05-android-app-support.md`);
deploy both together if that one is not live yet.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `<hash currently on the server>` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | yes (new Tailwind classes on the doors row) |
| Version | `VERSION` 2026.11.05.2 |

## What's in it
- "Explore 1CallFix" doors are smaller on phones: four fit side by side and a fifth peeks in at the edge.
- A **Food near you** card (restaurants with a menu and a licence, open ones first) next to the service and shop cards.
- The **Shop near you** card now always shows when a shop is live: if the products are not in a shop category yet,
  it shows the shops themselves.
- The **Parcel & delivery** card is off (owner adds a parcel banner instead). It can be switched back on in
  Admin → Growth → Homepage sections.
- New page order: Top banner → Explore doors → Most booked → Best offers near you → business cards
  (services, shop, food) → category icons → the rest. Still changeable in Admin → Growth → Homepage sections.
- "Offers on now" heading is now "Best offers near you" (unless a different text was saved in Website text & language).

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_05_100000_home_order_modules_after_offers` (settings only: saves the order above, Parcel card off)

Rollback count for `migrate:rollback --step=`: 1 (its `down()` does nothing; the old order is in the step-2 backup)

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy (phone)
- Home: top banner first, then four doors in one row with the next one peeking.
- Below Most booked / Best offers (each shows only with real data): a card per business — services, Shop near you, Food near you.
- No "Parcel & delivery" card.

## Admin settings to do after deploy (owner)
- Add the parcel banner: Admin → Banners, placement "top".
