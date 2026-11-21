# Release 2026-11-21.4 — Live Visitors + popularity line on item pages

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `82c74c4` (2026.11.21.3 backup failure alert) |
| Full test suite | 5104/5104 passed (2026-11-21, final code incl. popularity line), no known failures |
| Composer | no (`composer.lock` unchanged) |
| Front-end build (steps 6–7) | no. The ping script is a plain file in git (`public/js/visit-ping.js`); the admin screen uses the admin layout's Tailwind CDN |
| Version | `VERSION` 2026.11.21.4 |

## What's in it
Owner 2026-11-21: "how many users are online on our app, what they are checking and what they are going to book".

### Admin → Overview → Live Visitors (refreshes itself every 20 seconds)
- **Right now:** online now (seen in the last 3 minutes), in the app vs on the website, signed in, booking now.
- **What they are looking at:** one line per visitor: app/website, who (guest = "Visitor"), what kind of page and module,
  the page itself (link), city, pages opened, time on site.
- **About to book:** everyone on a cart, booking or checkout page in the last 30 minutes. Signed-in customers show name
  with **Call** and **WhatsApp** buttons (only to staff who can open Customers).
- **Today / 7 days / 30 days:** visitors (app/web), pages opened, started to book, orders placed (same numbers as the
  Dashboard); **looking → booking funnel per module**; **most viewed** items; **came from** (Google, Facebook, ...).
- Super Admin: **Tracking on/off** button on the screen (setting `visitors.tracking`, audited; on by default).

### Popularity line under item photos (owner 2026-11-21, the Amazon / OLX pattern)
- `<x-customer.popularity>` under the photo of services, shops, products, restaurants, and (through the photo gallery)
  hotels, halls, property, vehicles, rent-anything and events: **👁 "19 people viewed this in the past hour"** (else "in
  the past 24 hours") and **🔥 "50+ booked / bought / orders in the past month"** (services, products, shops, restaurants).
- **Real numbers only** (India's 2023 dark-pattern guidelines ban false urgency): distinct visitors from page_views;
  real orders (Dashboard booked() scopes, never cancelled, never unpaid attempts); orders rounded **down** (10+, 50+, 1K+).
- **Off until Super Admin taps a minimum** on Live Visitors → "Show customers how popular an item is" (people 3/5/10/20,
  orders 5/10/25/50, or Off; settings `popularity.views_min`, `popularity.orders_min`, audited). Wording is in Website
  text & language → "Popularity line" (English + Telugu). Cached 60 s per item. No new CSS classes (no build).

### How it counts
- `public/js/visit-ping.js` on every customer page (`components/layouts/customer`) sends "page opened" and, once a
  minute while the page is in view, "still here" to `POST /visit-ping` (CSRF-checked, 120/min per address).
- Visitor = a random id in that browser (nothing personal for guests); signed-in customers are linked to their account.
- Not counted: staff accounts (Super Admin or any admin role), the partner and rider apps, admin and partner pages,
  robots (`config/visitors.php` bot list), anything while tracking is off.
- Row scope: `visitors.view` (new), given to every role that has `dashboard.view`; a franchise team sees its own
  city / area only. Names and phones need `customers.view`.
- History older than 90 days is deleted daily (`visitors:prune`, 04:10).
- Load: one small request per page opened plus one per open page per minute (`VISITORS_BEAT_SECONDS`; raise to 120
  and `VISITORS_ONLINE_MINUTES` to 5 if the server gets busy). Super Admin can switch tracking off at once.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_21_400000_create_visitor_tracking_tables` (new tables `page_views`, `visitor_presence`; no existing table touched)
- `2026_11_21_400100_visitors_view_permission`

Rollback count for `migrate:rollback --step=`: 2

## Step 10 — scheduled commands that must be listed
- `visitors:prune` (daily 04:10)

## Browser checks after deploy
- Open the live site on a phone (not signed in as staff), open a service page.
- Admin → Live Visitors: "Online now" shows 1, the line shows the page you opened; Most viewed lists it.
- Open a booking page: it appears under "About to book".
- With the views minimum at 3: open one service page from 3 browsers; within a minute the page shows "👁 3 people viewed this in the past hour".

## Admin settings to do after deploy (owner)
- None required. Privacy page: consider one line saying the site counts page visits to improve the service.
