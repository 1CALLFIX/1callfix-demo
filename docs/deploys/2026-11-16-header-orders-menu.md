# Release 2026-11-16 — header search/location/language, hero banners, My Orders and the account menu

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `2dda0ebd` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (new Tailwind classes and `carousel.js`) |
| Version | `VERSION` file bumped to `2026.11.16` |

## What's in it
- Header (from `sm` up): the location shrinks to fit the city name; the search bar fills all the room left (no gap);
  the language picker is an Amazon-style "EN ▾" dropdown right beside the search bar (the languages that are on in
  Admin → Languages; one form per language, same route as before).
- Homepage hero banners (more than one live): tablets, laptops and large screens show several banners side by side
  (about 2.4 / 2.6 / 3.3 across) with the next one peeking in; phones show one banner with the next peeking in from
  the right. A single banner, the mid-page strip and category-page banners are unchanged. `carousel.js` now keeps
  every visible banner clickable and can reach the last banners.
- **My Orders** (`/orders`): ONE list of everything the customer ordered or booked, from every module whether or not
  the module is on today: services (incl. bundles), parcels, parcel packs, shop, food, grocery, pharmacy, hotels,
  function halls, events and property visits. Chips by module (only the ones the customer has used) and by progress.
  Online orders whose payment never arrived are not listed (no payment, no booking). Rentals (vehicles, equipment) are
  not listed: they cannot be booked on the web yet.
- Account menu (header dropdown): profile card, then labelled groups with icons: Your account (My Orders, Saved,
  Earnings, My account), Your business (shop / rider / partner dashboard, only for those who have one), Explore & help
  (Categories, Become a partner, Help Center). Removed from the menu: Services, Shop, My bookings, My parcels, Shop
  orders, My hall bookings, My tickets (all inside My Orders). Earnings is a direct menu entry only.
- My account page: only Saved addresses and Membership; Orders, Saved and Earnings are no longer repeated there.
- New website-text keys (Admin → Website text, with Telugu): `header.menu.orders`, `header.menu.group_account`,
  `header.menu.group_work`, `header.menu.group_more`.

## Step 5 — migrations that must be pending (exactly these)
- none

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Computer: location button only as wide as "Nellore Main"; search bar fills the row; "🌐 EN ▾" beside it opens the
  language list; right side reads Join Us, Book Now, menu, Sign in, Cart.
- Homepage: several banners side by side, the next one peeking in; arrows work; a click on a neighbouring banner opens it.
- Phone: one banner with the next peeking in; swipe works; header unchanged.
- Signed in: open the avatar menu: profile card, the three groups, no per-module order links. Open My Orders: chips,
  rows open the right page. My account shows Saved addresses and Membership only.

## Admin settings to do after deploy (owner)
- Admin → Website text: edit the four new menu words if wanted.
