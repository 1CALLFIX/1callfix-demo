# Release 2026-11-13 (8) — Function halls R2: customers book a hall online, the owner accepts

Use with `docs/DEPLOY_RUNBOOK.md`. Ships together with R1 (`2026-11-13-halls-r1-owners.md`) if R1 is not live yet.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `d58e17a` (R1 not deployed yet: both go together) |
| Full test suite | 4849 passed of 4850; known failures only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (new pages) |
| Version | `VERSION` file bumped to `2026.11.13.8` |

## What's in it
- Customer pages: `/{city}/halls` (cards: cover, ♡ Like, Share, kind, seats, AC, From ₹), `/{city}/halls/{hall}`
  (photo gallery, seating / standing / AC / parking / rooms, Directions, about, facilities, rules, slots with time and
  price incl. GST, deposit, date + slot → book; schema.org EventVenue). 404 while Function Halls is off for the city.
- `/halls/book` (signed in): change date / slot (free or taken shown at once), occasion, guests, contact, notes; the
  bill: hall price, GST (optional), booking fee, total, deposit; pay now = 25% advance (+ fee), or everything incl.
  deposit when the date is closer than 7 days; "the rest by <date>". Pay online (Razorpay) or wallet; never cash, no
  coupons. No payment, no booking: the slot is held 15 min while paying.
- Razorpay capture webhook (purpose `hall_booking`) → "waiting for the hall"; the owner gets a push / SMS / mail and
  must accept within 2 h (Hall portal → Bookings: Accept / Decline with a reason). Declined or not answered → full
  refund (wallet back to wallet, card / UPI through Razorpay). A payment that arrives after the hold, when someone
  else took the slot, is refunded in full.
- No double booking: slot times on the date (past midnight = next day) are checked against every booking holding a
  slot and every blocked day / slot, under a lock on the hall.
- `/halls/bookings` + `/halls/bookings/{code}`: status, money paid / due later, hall phone once confirmed; header menu
  "My hall bookings". Homepage / city hub door "Function halls" when on. Sitemap: real halls only.
- Admin → Rental → Hall bookings (read-only list, scoped). Sales dashboard: Function halls.

Not yet (R3): collecting the balance + deposit, customer cancellation by the Hall Settings rule, owner payout after
the event, deposit return / damage claim. **Keep Function Halls switched off in Admin → Modules until R3.**

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_15_100000_create_halls_module` (R1, if not live yet)
- `2026_11_15_110000_create_hall_bookings` (hall_bookings; payments.hall_booking_id; widens payments.purpose)

**Enum check before migrating:** `SHOW COLUMNS FROM payments LIKE 'purpose'` must list exactly these 13 values:
booking, wallet_topup, plan_subscription, parcel_order, taxi_ride, property_reservation, marketplace_order,
rental_reservation, hotel_reservation, booking_bundle, cancellation_fee, parcel_pack, event_order.

Rollback count for `migrate:rollback --step=`: `2` (or `1` if R1 was already live)

## Step 10 — scheduled commands that must be listed
- `halls:expire` (every minute)

## Walk-through (test logins)
Test Hall Owner (6000000114) + Test Customer (6000000107). Only after R3 in production; on a staging copy: switch
Function Halls on for Nellore → /nellore/halls → Test Function Hall → Morning, a date → pay → owner portal → Bookings →
Accept → customer sees "Your hall is booked".
