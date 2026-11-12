# Release 2026-11-12 — Hotels step 3: non-refundable price, change dates, area search, near me, map

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `5b698f3` |
| Full test suite | see the deploy report; known failures only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (new layout classes on the hotel search / booking pages) |
| Version | `VERSION` file bumped to `2026.11.12.3` |

## What's in it
Owner 2026-11-12: "make sure we can stand in the current aggregators" — step 3 of the comparison.
- **Cheaper non-refundable price.** Hotel portal → Rooms → "Non-refundable price: % cheaper" (1–50, blank = off).
  The hotel page then shows both prices for the room. A non-refundable booking keeps the whole amount when cancelled
  or the guest does not arrive, and its dates cannot be changed. Super Admin switch: Hotel Settings → Cancellation →
  "Hotels may offer a cheaper non-refundable price" (default on). App: `non_refundable`.
- **Change dates.** On the guest's booking page while free cancellation still applies: pick new dates → the booking
  form for the same room → pay the new stay. Once the new booking is CONFIRMED, the old one is cancelled free and
  refunded in full (a new booking the hotel declines or that is not paid never costs the guest their room).
  Super Admin switch: "Guests may change dates" (default on). Known limit: new dates that overlap the old ones need a
  free room on the overlapping nights.
- **One search finds hotels** (owner 2026-11-12: "we already have a search box in the top bar"). The top-bar search
  dropdown and the search results page now list bookable hotels in the city whose name, address (area, landmark) or
  description matches, where Hotels is on, with "See all hotels" → /{city}/hotels?q=… (shown there as a chip that
  clears the words). No second search box on the hotels page.
- **Near me and Map** on /{city}/hotels: "Near me" asks the browser for the location, shows "x km away" and sorts
  nearest first; "Map" shows every listed hotel as a pin with its price (Google Maps, the same browser key as the
  address map).

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_13_110000_add_non_refundable_and_date_change_to_hotels` (hotel_room_types.non_refundable_percent,
  hotel_reservations.non_refundable, hotel_reservations.replaces_reservation_id)

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Top-bar search "bus stand" or a hotel name: a Hotels group appears.
- /nellore/hotels: "Near me" and "Map" buttons; the map opens.
- A hotel page with a room that has a non-refundable % shows both prices.

## Admin settings to do after deploy (owner)
- Optional: Hotel Settings → Cancellation → the two new switches (both on by default).
- Hotels: set "Non-refundable price: % cheaper" on rooms they want to sell that way.
- Optional: Site text → the top-bar search hint (`header`) can now mention hotels.
