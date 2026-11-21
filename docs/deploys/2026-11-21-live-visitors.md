# Release 2026-11-21.4 — Live Visitors

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `82c74c4` (2026.11.21.3 backup failure alert) |
| Full test suite | 5096/5096 passed (2026-11-21, final code), no known failures |
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

### How it counts
- `public/js/visit-ping.js` on every customer page (`components/layouts/customer`) sends "page opened" and, once a
  minute while the page is in view, "still here" to `POST /visit-ping` (CSRF-checked, 120/min per address).
- Visitor = a random id in that browser (nothing personal for guests); signed-in customers are linked to their account.
- Not counted: staff accounts (Super Admin or any admin role), the partner and rider apps, admin and partner pages,
  robots (`config/visitors.php` bot list), anything while tracking is off.
- Row scope: `visitors.view` (new), given to every role that has `dashboard.view`; a franchise team sees its own
  city / area only. Names and phones need `customers.view`.
- History older than 90 days is deleted daily (`visitors:prune`, 04:10).

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

## Admin settings to do after deploy (owner)
- None required. Privacy page: consider one line saying the site counts page visits to improve the service.
