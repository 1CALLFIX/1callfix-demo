# Release 2026-11-03 — admin dashboard by module + side menu by module

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | the hash on the server (`git log -1` there; `273ba40` if the food fix is already live) |
| Full test suite | see the commit message / deploy report |
| Composer | no |
| Front-end build (steps 6–7) | no (admin uses the Tailwind CDN; no Vite asset changed) |
| Version | `VERSION` bumped to `2026.11.03.7` |

## What's in it
- **Dashboard tabs**, one colourful tab per module the viewer can open: All modules, Services, Parcel, Shop, Food,
  Taxi, Rental, Hotel (the last three marked "soon"). Period: Today / This Week / This Month / This Year.
- **All modules tab**: total orders, total sales, paid online, cash; one tile per module; a **Sales by module**
  table (orders, open, completed, cancelled, online, cash, sales) with a Total row; Daily Insights.
  Sales = orders placed in the period that finished (completed / delivered / checked out).
- **Each module tab**: that module's six numbers, a "screens & settings" row (the same links as its menu group),
  and the 10 newest orders (Services keeps its pipeline, stat cards, 7-day charts and Recent Bookings;
  Parcel keeps Parcel — Today).
- **Side menu regrouped** (`App\Support\AdminNav`, one list): Users & Partners · Services · Parcel · Shop · Food ·
  Taxi · Rental · Hotel · Marketing & Offers · Wallet & Rewards (wallet, loyalty, referrals, memberships) · Website & Pages · Finance ·
  Cities & Franchises (countries, cities, franchises, zones) · Messages & Alerts ·
  Settings & System. Each group has its own colour; groups start closed except the one you are in, and stay as
  you leave them. Every old screen is still in the menu (test checks the full list).
- New filtered links: **Shop Orders / Food Orders** (Marketplace Orders `?moduleFilter=shop|food`, with
  Shop + Food / Shop / Food tabs on that screen), **Restaurants** (Stores `?moduleFilter=food`), **Food problems**.
- Permissions unchanged: every link and every dashboard number uses the same permission and row scope as its
  own screen.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- /admin/dashboard: tabs show; All modules table has a Total row; click Shop, Food, Parcel, Services tabs.
- Menu: open Food → Food Orders shows only food orders; Restaurants shows only food stores.
- Settings & System → Modules (on / off) opens Modules.

## Admin settings to do after deploy (owner)
- none
