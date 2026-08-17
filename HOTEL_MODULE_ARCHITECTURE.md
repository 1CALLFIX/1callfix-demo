# Hotel / Stay Booking Module — Architecture & Product Decision

**Date:** 2026-08-17. **Status:** Built this session, under an explicit, detailed
mission brief (see the mission's own full spec, reproduced in this session's
transcript) — the same "authoritative requirements supplied" exception
`KNOWN_RISKS_AND_DECISIONS.md` item 34 always allowed for, not a violation of
this project's own "do not invent a schema without evidence" discipline.
Hotel/Stay was previously evidence-blocked (zero domain model found across
Glover 1.8.5, 6amMart 4.0.1, and the historical `DB_1cal_app_1.8.10.sql` dump
— see `PHASE_23_HOTEL_AND_RENTAL_REQUIREMENTS_GAP.md`); it is evidence-blocked
no longer, because real requirements were given directly, not inferred.

## 1. Top-level module decision

Hotel/Stay is its **own top-level module** (`hotel`), never nested inside
`rental`. This was an explicit instruction in the mission brief and is also
the technically correct call: Property Rental's reservation shape is a single
whole-unit date-range booking (one `property_reservations` row, one
`property_availabilities` boolean-per-day table); Hotel's shape is
fundamentally different — room-TYPE **quantity** inventory, multiple
**rate plans** per room type, **multi-room** bookings within one reservation,
and named **guests** distinct from the booking owner. Forcing Hotel through
Property Rental's engine would have meant either bolting a second, radically
different reservation shape onto one model (the same "don't merge unrelated
verticals onto one table" mistake this codebase's own `CreateBookingAction`
non-polymorphism decision — `PHASE_22_2_ORDER_ENGINE_ARCHITECTURE_DECISION.md`
— already rejected once) or silently dropping the multi-room/rate-plan
requirements to fit Property's shape. Neither was acceptable.

### Module slug: `hotel`, not `bookings`

`App\Support\Modules::ALL` already carried a **dormant placeholder** slug for
this exact vertical — `'bookings' => 'Hotel Booking'` — seeded into the real
`modules` table since Phase 22.1, with **zero consumer anywhere else in the
codebase** (confirmed by a full-repo grep before this build: the key
appeared only in `Modules::ALL` and `ModuleCapabilities::MAP`, never as a
string literal anywhere else). Rather than introduce a competing second slug,
this session renamed the placeholder to `hotel`, using the exact same safe
in-place-rename precedent this codebase already used twice
(`car_rental` → `property_rental` → `rental`): `module_activations.module_id`
is a proper integer FK, never the code string, so the rename cannot orphan
or break any existing activation row.

`hotel` (not the bare `bookings`) was chosen deliberately: `bookings` is
already an extremely overloaded identifier in this codebase — the real
`bookings` table/`Booking` model for the Service vertical, `App\Livewire\
Bookings\*`, etc. Reusing it as a *module* code too would be a real,
confusing collision risk the moment anyone reads `Modules::ALL['bookings']`
next to the `bookings` table and reasonably assumes they're related. They
are not. `hotel` has no such collision anywhere in the codebase.

## 2. Domain model

```
Accommodation (listing, like Property/Service)
  └── AccommodationType (extensible lookup: hotel/resort/guest_house/
      homestay/hostel/serviced_apartment, seeded; NOT a hardcoded enum)
  └── HotelRoomType (Standard/Deluxe/Suite/...; total_inventory = quantity)
        └── HotelRatePlan (Standard/Breakfast Included/Non-Refundable/...;
            nightly_rate — the actual sellable price)
        └── HotelRoomAvailability (per-room-type-per-date quantity counter
            + manual block flag + optional per-date price_override)

HotelReservation (order, Orderable, pending→confirmed→checked_in→
  checked_out→completed / cancelled)
  └── HotelReservationRoom (multi-room line items: room type + rate plan +
      count + price snapshot, one reservation can carry several)
  └── HotelGuest (named guests, distinct from the booking owner)
  └── HotelReservationStatusHistory
```

### Why quantity-based room inventory, not individual-room records

The mission brief explicitly allowed either "individual room inventory" or
"quantity-based room inventory," with quantity-based named as acceptable
when individual tracking isn't evidenced. No evidence anywhere in this
codebase or the brief suggests an operator needs to track *which specific
physical room* a guest occupies — only how many of a given type are free on
a given date. `hotel_room_availabilities` keys on `(hotel_room_type_id,
date)`, not an individual room id, so adding individual-room tracking later
(if ever justified) would be additive, not a rewrite.

### Why rate plans carry the price, not room types

A room type can be sold under several rate plans at different prices
(Standard vs. Breakfast Included vs. Non-Refundable) — this is the real,
named requirement, and it means the price naturally belongs on the rate
plan, not the room type. An `Accommodation`/`HotelRoomType` with no rate
plans yet is a valid, simply-not-bookable state, the same way an inactive
`Property` is.

### Concurrency safety

`HotelAvailabilityService` uses the identical two-part design
`PropertyAvailabilityService` already established and this codebase's own
mission brief explicitly demanded ("do not pretend a simple
application-level check-then-insert is concurrency-safe"): `lockForUpdate()`
on any existing availability rows in the requested date range (serializes
concurrent access once a row exists), plus the table's own
`unique(['hotel_room_type_id', 'date'])` constraint as the real database-level
backstop for the case where neither concurrent transaction sees a
pre-existing row yet. Extended with real quantity accounting
(`rooms_booked` vs. `total_inventory`) rather than Property's boolean, since
a multi-room hotel booking genuinely needs a count, not a flag.

A real, proven bug was found and fixed during this build (not hypothetical):
`HotelRoomAvailability.date` cast to Eloquent's `'date'` type silently
serialized with a full `Y-m-d H:i:s` timestamp on write, breaking the plain
`'Y-m-d'`-string `whereIn('date', ...)` lookup the availability service
depends on and causing a false UNIQUE-constraint collision on the second
reservation for an already-touched date. This is the *exact same* defect
`PropertyAvailability` already found and fixed in Phase 22.7 — the identical
fix (no cast, plain string throughout) was applied here too, this time
caught by a failing test before commit rather than discovered in
production.

## 3. Financial integration — no parallel systems

Per the mission's explicit instruction, Hotel does **not** get its own
wallet, commission, or payment system. It reuses the existing shared
engines exactly as every other vertical does:

- `CommissionService::applyForHotelReservation()` — the sixth caller of the
  shared `applyForFieldWorkerOrder()` core (Parcel/Taxi/Property/Rental/
  Marketplace/Hotel), earner resolved through `HotelReservation::provider()`
  → `Accommodation::provider` (the same `Provider` concept every other
  vertical's "owner" resolves to — not a new entity).
- `CancellationService::calculateFeeForHotelReservation()` /
  `refundIfPaidForHotelReservation()` — hours-before-check-in semantics
  (like Property Rental, not Parcel/Taxi's elapsed-since-creation shape),
  own `hotel.*` Setting namespace so an admin can configure Hotel's window
  independently of Property Rental's `rental.*` keys without being forced
  to couple them. Every rate defaults to a neutral `0`/`48h` — no invented
  commercial value.
- Document Engine (`DocumentService`) — extended generically via the
  existing `ORDERABLE_RELATIONS` map (`'hotel_reservation' => 'hotelReservation'`),
  the same pattern already covering `parcel_order`/`taxi_ride`/
  `property_reservation`/`marketplace_order`/`rental_reservation`. No new
  template, no new rendering engine.
- Admin scope (`Payments\Index`, `Commissions\Index`, `CommissionsExport`,
  both `DocumentController`s) — all extended to the same generic
  candidate-array pattern already covering every other vertical, closing
  the same "new vertical falls through fail-closed scope, invisible to
  franchise-scoped admins" gap this codebase's own 2026-08-17 hardening
  session already found and fixed for four other verticals.

## 4. Business decisions deliberately NOT made here

Per the mission's own explicit boundary, the following remain configurable
but un-set, or descriptive-only:

- **Final commission/platform-fee percentages** — reuses `Franchise.
  platform_fee_percent`/`commission_value`, admin-configurable, no Hotel-
  specific override invented.
- **Cancellation fee values, free-cancellation window** — `hotel.*` Setting
  keys, default `0`/`48h`, same "safe-zero until an admin deliberately sets
  it" precedent every other vertical's cancellation Setting uses.
- **`HotelRatePlan.cancellation_policy_label`** (`flexible`/`non_refundable`)
  is **descriptive/display metadata only** — it does not currently force a
  different fee calculation. Whether a `non_refundable` rate plan should
  enforce a 100% fee is a real, open business decision, documented in
  `KNOWN_RISKS_AND_DECISIONS.md`, not invented here.
- **Check-in/check-out exact times** — `Accommodation.check_in_time_start`/
  `check_in_time_end`/`check_out_time` default to `14:00`/`22:00`/`11:00`,
  the same real established precedent `properties` (Property Rental) already
  shipped with for the closest analogous entity — per-accommodation
  editable, never a business decision baked in as immutable.
- **Guest identity/KYC requirements** — none invented; `HotelGuest.name` is
  the only required field, matching the mission's explicit "do not invent
  mandatory identity/KYC rules" instruction.
- **Reviews** — deliberately NOT built (`ModuleCapabilities::MAP['hotel']
  ['reviews'] = false`), per the mission's own explicit "do not implement a
  complete review system automatically" instruction. The `hotel_reservation_id`
  nullable FK exists on `reviews` (same pattern every other vertical's FK
  addition follows), so a future `HotelReviewService` has the schema ready,
  but nothing writes to it today.

## 5. Deliberately deferred / not built

Documented honestly rather than silently dropped or fabricated:

- **Seasonal/date-specific rate calendars beyond per-date override** —
  `HotelRoomAvailability.price_override` supports a single per-date override
  price (same mechanism Property Rental already has), which covers "this one
  date costs more." A richer seasonal-rule engine (date-range rules, rule
  priority) was not asked for and was not built speculatively.
- **Occupancy-dependent pricing beyond capacity validation** — room
  types enforce a max-adults/max-children capacity against the selected
  rooms, but the rate plan's nightly price does not itself vary by guest
  count (e.g., no extra-adult surcharge). No evidence/spec for that pricing
  shape was given.
- **A dedicated Accommodation Types / Room Inventory / Rate Plans /
  Guests / Policies admin screen per the mission's own aspirational
  sidebar sketch** — built as **two** real top-level screens
  (Accommodations, Hotel Reservations), matching the exact scope ratio
  Property Rental's own admin build established, with Room Types/Rate
  Plans/Room Inventory management nested inside the Accommodations screen
  (same nested-table precedent `Plans\Manage`/`Geography\Manage`/
  `PerformanceCampaigns\Manage` already use) rather than four to nine
  separate top-level screens with no real access-control distinction
  between them. `AccommodationType` itself has no dedicated CRUD screen,
  matching `PropertyType`'s own established precedent in this codebase
  (seeded via migration, admin-extensible at the DB level, no UI built
  speculatively).
- **Search filters beyond geography/name/date-range** (amenities, price
  range) — not implemented; the mission's own "do not create a massive
  search engine prematurely" instruction applies, and no evidence exists
  for which filters a real customer app would actually need first.

## 6. Ships disabled

`modules.is_implemented` for `hotel` stays `false` — built fully, gated
inert, same "build completely, keep disabled pending a real business
activation decision" precedent every non-Service vertical in this codebase
follows (Parcel, Taxi, Property Rental, Rental, Marketplace Foundation +
Ecommerce/Food/Grocery/Pharmacy). Module activation is independent of
`rental` in both directions — verified by a dedicated regression test
(`test_hotel_reservation_is_not_blocked_by_rental_being_disabled`,
`test_enabling_rental_does_not_implicitly_enable_hotel`).
