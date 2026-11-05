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
- **Rate & review**, inside the Android apps only: customer app top menu; rider, seller/restaurant and service-partner
  More tab. Opens that app's Play Store page (com.call.customer, com.onecall.deliveryboy, com.onecall.vendor).
  Never shown in a normal browser. Label in Website text & language (`app.rate`, Telugu included).

- **Delete account (Google Play)**: public page `/delete-account` with the steps for every app. Customers delete
  themselves (blocked while wallet has money or a booking/order/parcel is open); sign-in stops at once and name,
  phone, email and address text are erased 30 days later (`accounts:erase-closed`, daily 03:30). Bills and orders
  stay. Partners only send a request; Admin → Users & Partners → Account deletions (Super Admin) closes it after
  dues are settled, and can Reopen within 30 days. Links: customer Account page, partner More tab.
- **Shop sub-categories**: the shop page shows a category when its sub-categories have products (master-list
  products sit in sub-categories), and picking a category shows its sub-categories as a second row. Seller and
  commission category drop-downs list each sub-category under its own category.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_05_100000_home_order_modules_after_offers` (settings only: saves the order above, Parcel card off)
- `2026_11_05_110000_add_account_deletion_columns_to_users` (3 nullable timestamps on users)

Rollback count for `migrate:rollback --step=`: 2 (the order migration's `down()` does nothing; the old order is in the step-2 backup)

## Step 10 — scheduled commands that must be listed
- `accounts:erase-closed` (daily 03:30)

## Browser checks after deploy (phone)
- Home: top banner first, then four doors in one row with the next one peeking.
- Below Most booked / Best offers (each shows only with real data): a card per business — services, Shop near you, Food near you.
- No "Parcel & delivery" card.
- In each Android app: Rate & review (customer: top menu; partner apps: More tab) opens the Play Store page.
  In Chrome on the same phone it is not there.

- `/delete-account` opens signed out and shows the steps.
- `/nellore/shop`: tap a category → a second row of its sub-categories.

## Admin settings to do after deploy (owner)
- Play Console, each of the 3 apps → App content → Data safety → Delete account URL: `https://www.1callfix.com/delete-account`
- Add the parcel banner: Admin → Banners, placement "top".
