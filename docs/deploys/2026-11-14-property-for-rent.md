# Release 2026-11-14 — Property for rent (monthly: homes, shops, offices)

Use with `docs/DEPLOY_RUNBOOK.md`. Goes out together with `2026-11-14-one-rental-and-hall-details.md` (same branch).

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `<hash currently on the server>` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | yes |
| Version | `VERSION` bumped on merge |

## What's in it
- **Property for rent (monthly)**, part of the one Rental module (Rental Settings → Rental kinds → "Property for rent").
  Nightly stays stay with Hotels; the old nightly "Properties" / "Property Reservations" admin screens are removed
  from the menu and the old nightly booking is switched off (`rental.legacy_nightly_stays`, never set). No data deleted.
- **Owners** join on /partners → "Property owner" → the one partner sign-up (mobile code, documents). The Rental portal
  (`/venue`, now titled "Rental") shows halls and homes together: a 6-step colourful editor (kind, size, furnishing,
  floor, facing chips; rent; deposit by month buttons; "Ready now"; who they rent to; area chips; landmark; address;
  Use my location; facilities by section; rule chips; photos), Rented out / Still available, and a Visits tab.
- **Team**: Admin → Rental → Property for rent: approve, send back with a reason, approve or refuse changes to a live
  home's photos / description / landmark / address, feature, switch off, and a Visit requests tab. Permission
  `homes.manage` (Super Admin and the roles that already approve halls).
- **Customers**: `/{city}/property` with tap filters (budget, size, kind, furnishing, who can rent, area, cheapest
  first), `/{city}/property/{property}` (all photos, rent, deposit, maintenance, size, floor, facing, furnishing, ready date,
  facilities by section, rules; exact address hidden), "Ask to see this home" (day chips, time chips, who you are,
  message), "Show owner's number" (when allowed, daily limit), My property visits (address, directions and number once
  accepted; cancel). Rentals page and door show houses too. ♡ Like + Share on every home.
- **Multi city / terrain / climate**: every list (kinds, sizes, furnishing, tenants, facing, floors, facilities with
  sections like "Weather and climate" and "Terrain and access", rules, deposit months, budget buttons, visit times,
  area unit sq ft / sq m / sq yd) is in Rental Settings and can be set per franchise or zone. On a franchise or zone
  the page shows the list it inherits and a "Start from this list and change it here" button.
- No money moves (listing and visits are free).
- **One photo gallery everywhere**: hotel, event, hall and home pages all show every photo the same way (arrows, "2 / 7"
  counter, all photos as tiles, full-screen view, ♡ Like + Share). Facilities on hotels, halls and homes are Super Admin lists.

## Step 5 — migrations that must be pending (exactly these, after the two of the other note)
- `2026_11_15_170000_create_house_listings`

## Step 10 — scheduled commands that must be listed
- `homes:tidy` (daily 02:15: visit requests not answered by the visit day close as "No reply in time")

## Test login and walk-through
- `php artisan test-accounts create --password=...` adds `test.homeowner@1callfix.test` (Test Property Owner) with one live
  test property of EVERY kind (7 to live in, 6 for business), every option filled; earlier: four
  live test homes (2 BHK flat, 3 BHK house, 1 RK room, PG). `test-accounts disable` switches them off.
- Customer: home → Rentals → Property for rent (also try kind "Shop") → filter "Up to ₹15,000" → open the 2 BHK → see all photos → Ask to see
  this home (tomorrow, Evening) → My property visits shows "Waiting for the owner".
- Owner (partner sign-in with the test property owner): Visits → Accept with a note → customer's My property visits shows the
  address, Directions and the owner's number.
- Owner: Add another home for rent → fill the 6 steps → Send for review → Admin → Property for rent → Approve.

## Admin settings to do after deploy (owner)
- Admin → Modules: Rental on for Nellore (if not already).
- Rental Settings → Property for rent: check the lists; set per-city budget buttons and deposit months; turn on
  "Take property owner sign-ups on /partners" when ready to recruit.
