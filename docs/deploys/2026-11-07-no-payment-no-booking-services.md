# Release 2026-11-07 — No payment, no booking (service bookings)

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `10124e0` |
| Full test suite | 4602 tests, 4601 passed; 17 tests written for the old rule (unpaid online bookings counted as bookings) updated; known NotificationCenterAuditTest .env failure only |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (new Blade classes on the order page; build to be safe) |
| Version | `VERSION` 2026.11.07.11 |

## Owner rule 2026-11-07
"After payment, the service should be booked … no payment, no booking." Seen: booked online, went back from the
payment window, booked again with cash → two bookings.

## What's in it (single service bookings; parcel, shop, food and the services cart follow in the next release)
- An online booking is only a **payment attempt** until Razorpay captures the payment (`Booking::isPaymentAttempt()`).
  Until then: not in the customer's My bookings, not in Admin → Bookings / Dashboard / customer pages / counts, no
  "booking created" SMS/notification, no admin new-booking alert, nothing sent to professionals (that was already so).
- **On capture it becomes the booking:** "booking created" + payment received go to the customer, the admin alert
  fires, dispatch starts (as before).
- **Payment screen instead of a booking page:** "Complete your payment — your booking is made only after the
  payment", Pay ₹X (Razorpay now opens by itself after Confirm booking), **Book with cash instead** (only when no
  coupon / offer / member price / referral balance was used, not scheduled, and cash is on for the area — THUMB RULE)
  and "Don't book now". After paying: "Confirming your payment…" (refreshes itself; no second Pay button).
- **Booking again replaces the unpaid attempt** (online or cash): never two.
- **Ends quietly after 15 minutes unpaid** (Super Admin → Site controls → "Online payment: time to pay", 5–120),
  every minute by `bookings:expire-unpaid-attempts`; no "cancelled" message, no fee. A payment arriving later is
  refunded in full by the existing late-capture refund. An ended attempt can't be paid (web + API).
- Admin → Bookings → **"Payment attempts (not booked)"** lists them for support calls.
- Voice search: no mic inside the Android apps (the app page gets no microphone, so it showed "Microphone is blocked"); browsers keep it.
- Note: an online booking made from the admin call-centre form follows the same rule (customer must pay within the
  time); choose cash for phone bookings where the customer pays the professional.

## Step 5 — migrations that must be pending
- none

## Step 10 — scheduled commands that must be listed
- `bookings:expire-unpaid-attempts` (every minute)

## After deploy
- The 2 unpaid online service bookings already on the live site (incl. test NLR-0711-00000003) end on the first
  sweep run (they are older than 15 minutes) — expected; they were never paid.

## Browser checks after deploy
- Book a service with Online: Razorpay opens by itself; close it → "Complete your payment" screen; My bookings
  does not list it; Admin → Bookings does not list it, "Payment attempts" does.
- "Book with cash instead" → booking form with Cash chosen → confirm → ONE booking, the attempt is gone.
