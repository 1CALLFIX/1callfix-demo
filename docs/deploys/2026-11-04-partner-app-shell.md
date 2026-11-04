# Release 2026-11-04 — Partner apps: one bottom bar, More tab, big switches

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `d9ccf07` (VERSION 2026.11.04.3) |
| Full test suite | see the commit message |
| Composer | no |
| Migrations | none |
| Front-end build (steps 6–7) | **yes** (new layout classes) |
| Worker restart (step 9) | yes |
| Version | `2026.11.04.4` |

## What's in it (owner 2026-11-04: "faster, easy to use, like the delivery-boy apps — reference, not a replica")
- **Rider, seller/restaurant and service-partner apps share one app-style bottom bar** on every screen size:
  - Rider: Home · Earnings · History · More
  - Seller / restaurant: Home · Orders · Products (Menu) · Earnings · More (the old top row scrolled sideways on a phone)
  - Service partner: Home · Jobs · Earnings · More (replaces the hamburger drawer)
- **More tab** in each app: name and ID on top, quick tiles (cash dues / wallet, Bank & UPI, Get paid; shop or
  restaurant details for sellers), the less-used pages (add-ons, add many, problems/returns, history, activity,
  shifts), the Help numbers from Admin → Partners page → Support numbers, switch to another partner role, Sign out.
- **Rider home:** one big Online/Offline switch card and a Today card (earned today, jobs today, cash dues).
- **Seller home:** one big Open/Closed switch card (same look as the rider's).
- No money rules, dispatch or order logic changed.

## Step 5 — migrations
- none

## Step 10 — scheduled commands
- none new

## Test logins
- Existing `test.rider`, `test.seller`, `test.restaurant`, `test.provider` (`php artisan test-accounts`).

## Browser checks after deploy (phone width)
- /rider: switch card, Today card, bottom bar; More shows Rider ID, Bank & UPI, Get paid, Sign out.
- /seller as test.restaurant: Open/Closed switch, bottom bar Home · Orders · Menu · Earnings · More; More has
  Restaurant details, Add-ons, Problems with orders.
- /provider: bottom bar Home · Jobs · Earnings · More; More has Job history, Activity, Wallet & dues, Sign out.
