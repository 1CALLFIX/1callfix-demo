# Release 2026-11-07 — Pin the address on a map

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `4b1a23f` |
| Full test suite | 4593 tests, 4592 passed; only the known NotificationCenterAuditTest .env failure |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (new resources/js/address-map.js) |
| Version | `VERSION` 2026.11.07.10 |

## What's in it (owner 2026-11-07: "adding maps location is not adding anything")
- **Map with a pin on every customer add-address form** (Account → Addresses add + edit, booking "Add a new address",
  shop checkout "New address"). Swiggy / Urban Company style: the red pin stays in the middle, the customer moves
  the map under it; a search box ("Search your area, street or building") jumps to a place; "Use my current
  location" moves the map to the phone's position.
- When the map stops, **street, city and PIN fill in by themselves** from Google's address for that spot (only empty
  fields, or ones the map filled; never what the customer typed), and the service area is checked
  ("Pinned — service area …" / "We don't serve this exact spot yet").
- **Real spot, not the area centre.** Before, an address saved without a GPS fix got the CENTRE of the service area
  as its location (shop checkout: the shop's own location), so a professional / rider would be sent to the wrong
  place. With the map available, an address now needs a pin ("Put the red pin on your location on the map first").
  Editing an address and moving the pin moves the saved spot and its service area.
- Same Google browser key the site already uses (Maps JavaScript + Geocoding, both checked on the live site). No key
  = no map and the old form, unchanged. Words: Website text → "Address map (pin)" (en + te).
- Tests never use the real Google key (phpunit.xml).

## Step 5 — migrations that must be pending
- none

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Phone, signed in: Account → Addresses → Add an address: map shows; drag it, the street / city / PIN fill in and
  "Pinned — service area …" shows; Save works. Save before moving the map: "Put the red pin…".
- "Use my current location": the map jumps to you and fills the address.
- Book a service → Add a new address: same map. Do not complete a booking.
