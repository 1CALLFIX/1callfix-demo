# Release 2026-11-18 — Vehicles for rent (OLX-style)

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `<hash currently on the server>` (the 2026-11-17 release `a933df0d` must be live first, or deploy both together) |
| Full test suite | 4960/4961 passed (2026-11-18, one full run; the 3 guard counts updated for this release re-run green); known failure only: NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default (.env) |
| Composer | no (`composer.lock` unchanged) |
| Front-end build (steps 6–7) | **yes**: new colour classes on the vehicle pages (sky, cyan) are not in the current build |
| Version | `VERSION` 2026.11.18 |

## What's in it
- **Vehicles for rent, OLX-style** (owner decision 2026-11-18). An owner lists a car, SUV, bike, auto, van, truck,
  tractor or bus. The team approves it, customers in the city browse it and call or WhatsApp the owner. No booking or
  payment runs between customer and owner; the platform earns the listing fee (same engine as property).
- **Joining:** a new "Vehicle owner" card on /partners. It goes to the one partner sign-up when Rental Settings →
  "Take vehicle owner sign-ups" is on. Documents: owner ID and vehicle RC (required), insurance and bank details
  (optional). The old "Vehicle or equipment owner" card is now "Equipment owner" (shown only where Equipment is on).
- **Rental portal (/venue):** vehicles appear next to halls and property, with a checklist. The editor has 5 coloured
  steps, all taps except company, model, prices, landmark and pickup address. A live vehicle changes its price at once,
  while photos, description and address wait for the team. Owners can tap "Not available now" or "Available again", and
  pay the listing or feature fee (online or wallet).
- **Admin → Rental → Vehicles for rent:** approve, send back with a reason, approve or reject changes, feature, switch
  off. New permission `vehicle_listings.manage` (given to Super Admin and every role that has `homes.manage`).
  "Vehicles" lower in the menu is the old admin catalog and is unchanged.
- **Customers:** `/{city}/vehicles` (tap filters: vehicle, self-drive / with driver, fuel, area, cheapest first) and
  `/{city}/vehicles/{vehicle}` (photos with ♡ Like and Share, price per day / hour, deposit, free km, features, the
  owner's rules). The pickup address is never shown. "Show owner's number" (signed in, daily limit) is followed by
  Call and WhatsApp buttons. A Vehicles tile and row are on the Rentals page.
- Partner documents: property owners and vehicle owners now get their own document list (`home` / `vehicle`) instead
  of the service-provider list.
- `homes:tidy` already ends lapsed paid features for vehicles too (no new scheduled command).

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_18_100000_create_vehicle_listings_table`
- `2026_11_18_100100_vehicle_listings_permission_and_documents`

(Plus the 2026-11-17 release's migrations if that release is not live yet.)

Rollback count for `migrate:rollback --step=`: `2`

## Step 10 — scheduled commands that must be listed
- `homes:tidy` (already there; no new command)

## Test login and walk-through (thumb rule: every module ships with a test login)
- `php artisan test-accounts create --password=<owner's password> --franchise=<id>` now adds
  **test.vehicleowner@1callfix.test** with one approved, live test vehicle of every kind (all options filled).
- Walk-through:
  1. Admin → Rental Settings: turn on **Rental kinds → Vehicles** for the test city.
  2. Open `/<city>/rentals`: you should see the Vehicles tile. Open `/<city>/vehicles`, try each filter, open a vehicle,
     check that photos, Like and Share work, and that no pickup address is shown.
  3. Sign in as the test customer: tap "Show owner's number", then check that Call and WhatsApp open.
  4. Sign in at the partner sign-in as the test vehicle owner: the Rental portal lists the vehicles. Open one,
     change the price (it changes at once) and the description (it waits for review), then tap "Not available now"
     and check it is hidden. Tap "Available again".
  5. Admin → Rental → Vehicles for rent: approve the waiting change.
  6. Optional: set a small listing fee on a test zone, approve a new vehicle, and pay from a funded test wallet.
- `test-accounts disable` before public launch switches the test vehicles off too.

## Browser checks after deploy
- `/<city>/rentals` (Vehicles tile only where Vehicles is on), `/<city>/vehicles`, one vehicle page, `/venue` as the
  test vehicle owner, Admin → Rental → Vehicles for rent, `/partners` (Vehicle owner card only where Vehicles is on).

## Admin settings to do after deploy (owner)
- Rental Settings → **Rental kinds → Vehicles**: on, only where you want vehicles (off by default).
- Rental Settings → **Vehicles for rent · Joining → Take vehicle owner sign-ups**: on when ready.
- Optional: the vehicle lists (kinds, fuel, features, rules), price range, photos needed, listing days, listing and
  feature fees, and the owner's number switch and daily limit, all per city or zone.
- If you had renamed the /partners "rental" card before, check its wording: it is now the Equipment card.
