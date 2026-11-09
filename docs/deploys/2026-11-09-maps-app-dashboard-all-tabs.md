# Release 2026-11-09 — map links open the maps app, a livelier dashboard, every list opens on All

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `32fec13` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (new dashboard classes; CSS `app-CEZqVGar.css`) |
| Version | `VERSION` 2026.11.09.8 |

## What's in it
- Owner (photo): the rider's **Directions** opened google.com/maps in Chrome, a tiny desktop map. Every map link now
  goes through `App\Support\MapLink` / `<x-ui.map-link>`:
  - Android (Rider, Partner and Customer apps, and Chrome on Android): opens the **Google Maps app** straight into
    navigation (`intent://…;package=com.google.android.apps.maps`). A phone without Google Maps gets the web page.
  - iPhone: Apple Maps. Computers: Google Maps in a new tab, as before.
- Where: rider food / shop / parcel / return Directions (pickup and drop), partner job page (was "Open in maps",
  now a "Directions" button), customer parcel "Open map" (rider's position), Contact page map link, admin parcel
  "Where the rider was" / photo "Map", admin shop change-approval location.
- No app update needed: the app shells already hand intent:// links to Android.

- **Admin dashboard** (owner: "modules that are not built are shown", "every tab, button and link clickable", "a
  circle or graph", "show today's date", "a pleasant atmosphere"):
  - Only modules built **and** switched on somewhere (Admin → Modules) show: tabs, cards, table. Taxi / Rental /
    Hotel stay off until switched on; then they appear by themselves.
  - A blue welcome banner: "Good evening, <name>", Dashboard, today's date ("Monday, 9 November 2026").
  - The period line names its dates: Today · Mon, 9 Nov 2026 / This Week · 9 Nov – 9 Nov 2026 / November 2026 / 2026.
  - Top cards are links (Total orders / sales → the module table; Paid online / Cash → Payments filtered online / cash).
  - New "on duty now" tiles: Riders on duty, Partners on duty, Shops & restaurants open (x / total, with a bar), each
    opening its list.
  - A circle (sales share per module; orders when nothing finished yet) and a 7-day bar chart of orders per day,
    coloured per module. Module names in the legend and the table open that module's tab.
  - Recent orders: the order code opens the order (bookings page; parcel / shop / food list searched for that code).
- **Every list opens on All** (owner: "the first tab should be All"): Partners → People / Businesses open on
  **All partners** (Needs review is second and keeps its count and red overdue badge); Shop returns, Parcel claims and
  Changes to approve get All first and open on it. Other lists already opened on All.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Rider app, an active job → Directions: Google Maps app opens with the route (not Chrome).
- Admin → Dashboard: banner with today's date; only Services, Parcel, Shop, Food tabs; tap Today → "Today · <date>";
  circle and 7-day bars; tap each card.
- Admin → Partners: People and Businesses open on All partners.
