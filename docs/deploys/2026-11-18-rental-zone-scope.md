# Release 2026-11-18.2 — Rental kinds follow each zone and franchise

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `a064cbe` (2026-11-18 vehicles release) |
| Full test suite | 4962/4963 passed (2026-11-18, one full run); known failure only: NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default (.env) |
| Composer | no (`composer.lock` unchanged) |
| Front-end build (steps 6–7) | no (PHP only; no new classes in views) |
| Version | `VERSION` 2026.11.18.2 |

## What's in it
- Found in the 2026-11-18 live walk-through. Rental Settings → Rental kinds (Function halls, Property for rent,
  Vehicles) can be set per franchise or per zone, but the customer city pages only looked at franchises, and their
  lists showed every approved listing of the city once any one franchise had the kind on.
- Now (`CityModules::rentalKindScopesIn` / `whereRentalKindOn`):
  - A city's Rentals, Vehicles, Property and Function halls pages open when the kind is on for **any franchise or any
    zone** of the city (a zone-only switch now counts).
  - The lists, the item pages, the counts and the Rentals page show only items whose **own zone** (or, with no zone,
    own franchise) has the kind on: zone → franchise → city → global, the same chain "Show owner's number" already used.
  - A zone's own "Off" wins over its franchise's "On".
- Example: city with franchises A (zones A1, A2) and B (zone B1), Vehicles on for zone A1 only → `/city/vehicles`
  opens with A1's vehicles only; A2's and B1's vehicle pages are 404.
- Tests: `VehicleListingTest::test_a_city_with_several_franchises_and_zones_shows_only_vehicles_where_vehicles_is_on`,
  `HouseListingTest::test_property_switched_off_for_one_zone_hides_only_that_zones_homes` (both fail without the fix).

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- `homes:tidy` (unchanged)

## Test login and walk-through
- No new test login (no new app or role). Uses `test.vehicleowner@1callfix.test` / `test.homeowner@1callfix.test`.
- Nellore has one franchise and one zone, so nothing a customer sees changes there. Check:
  1. Rental Settings → Franchise Nellore → Vehicles: Inherit (off), then Zone Nellore Main → Vehicles: On.
     `/nellore/rentals` shows the Vehicles tile and `/nellore/vehicles` lists the 9 test vehicles.
  2. Zone Nellore Main → Vehicles: Off → `/nellore/vehicles` is 404 and the tile is gone.

## Browser checks after deploy
- `/nellore/rentals`, `/nellore/property`, `/nellore/halls`, `/nellore/vehicles` (while Vehicles is on).

## Admin settings to do after deploy (owner)
- Decide where Vehicles stays on: today it is on at franchise Nellore (walk-through). Zone Nellore Main also has
  Equipment, Function halls and Property set to On (owner's test, 2026-11-18): set back to Inherit if not wanted.
