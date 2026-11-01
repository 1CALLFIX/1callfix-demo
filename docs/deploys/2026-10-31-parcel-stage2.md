# Release 2026-10-31 — Parcel Stage 2

Use with `docs/DEPLOY_RUNBOOK.md`. This release sits on `main` on top of two releases that were pushed but never
deployed: **super-app shell** (`2026-10-31-super-app-shell.md`) and **shop go-live** (`2026-10-31-shop-go-live.md`).
All three go live together: read both notes too (their browser checks and settings).

| | |
|---|---|
| Deploys | `main` at the merge commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `5c23234` (Test shop) — stop if the server shows anything else |
| Full test suite | 4329 passed of 4330 (branch run 2026-11-01); known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (new customer, rider and admin markup) |
| Version | `VERSION` file bumped to `2026.10.31.3` |

## What's in it (plain words)
Owner request 2026-10-31: "finish parcel 100%", kept **simple to use and easy to operate**. Every new extra is
**off until Super Admin switches it on** in Parcel Settings. While it is off, the customer sees nothing new and
booking stays the same few taps.

- **More drop points** (off until "Most drop points" is set): one parcel, several drops on the way. Price = the
  route through the drops + a fee per extra drop. Each drop gets its own recipient and its own delivery code. Cash
  only as "Pay at Pickup".
- **Return trip** (off until "Return trip price" % is set): the rider brings something back to the sender; the
  sender gives a return code to finish.
- **Parcel protection** (off until the protection % is set): optional add-on, fee = % of the declared value. The
  sender can report the parcel lost or damaged with photos. **No money moves by itself**: Admin → Finance → Parcel
  Claims, approved under the normal money-approval rules (limits, two people above the limit, reason, audit).
  Call it "protection", not "insurance". The terms need a lawyer before switching it on.
- **Prepaid parcel packs for businesses**: Admin → Parcel Packs creates packs (e.g. 20 parcels for ₹X). Customers
  buy them online or from the wallet at `/parcel/packs`. A pack is a benefit, so **a pack can't be used with
  cash** (thumb rule, tested). A cancelled order gets its pack credit back.
- **Cash on delivery for shops** (off until "Highest amount" is set): the rider collects the item price at the
  drop, owes it to HQ in their cash dues, and the sender's wallet is credited once the rider has paid it back. One
  drop only.
- **Chat**: a simple message box on the parcel page (sender) and on the current job (rider), only while the rider
  is on the job. Calling still works.
- **Rider distance**: the sender sees "Rider is 2.4 km from the drop · updated 1 min ago" with an **Open map**
  button (Google Maps). The rider's phone sends its location every 30 s while carrying a parcel.
- **Star rating**: after delivery the sender rates the rider 1–5 stars once (optional comment). It updates the
  rider's rating.
- **Block a sender**: Admin → Customers → open the customer → "Send parcels: Block". Only parcel sending stops;
  the rest of the account keeps working.
- **Old safety photos deleted** (off until "Keep rider safety photos for" is set): deleted that many days after the
  parcel is finished, never while a protection claim is open.
- App API: quote/store accept `extra_stops`, `return_trip`, `declared_value`, `cod_amount`, `use_parcel_pack`;
  new `GET/POST /api/parcel-orders/{id}/messages`, `POST /api/parcel-orders/{id}/rating`,
  `POST /api/parcel-orders/{id}/protection-claims`, `GET /api/parcel-packs`, `POST /api/parcel-packs/{id}/buy`,
  `POST /api/worker/parcel-orders/{id}/return`; worker deliver accepts `stop_id`.

## Step 5 — migrations that must be pending (exactly these, 6 in all)
- `2026_10_31_200000_add_rider_pickup_to_marketplace_returns` (from shop go-live)
- `2026_10_31_700000_parcel_stage2_routes`
- `2026_10_31_710000_parcel_protection`
- `2026_10_31_720000_parcel_packs` (**widens the `payments.purpose` enum**)
- `2026_10_31_730000_parcel_cod`
- `2026_10_31_740000_parcel_chat_and_ratings`

**Before migrating, check the enum.** Run `SHOW COLUMNS FROM payments LIKE 'purpose';` on production and confirm
the full list is exactly:
`booking, wallet_topup, plan_subscription, parcel_order, taxi_ride, property_reservation, marketplace_order,
rental_reservation, hotel_reservation, booking_bundle, cancellation_fee`.
The migration adds `parcel_pack`. If production has any other value, or one is missing, STOP.

Rollback count for `migrate:rollback --step=`: `6` (the packs migration refuses to roll back once a
`parcel_pack` payment exists).

## Step 10 — scheduled commands that must be listed
- `refunds:escalate-parcel-claims` (every 15 minutes; does nothing until the escalation hours are set)
- `parcel:purge-safety-photos` (daily 03:30; does nothing until the keep-days setting is set)

## Browser checks after deploy
- Admin → Parcel Settings: new groups (Multi-drop and return trip, Parcel protection, Cash on delivery (shops)), all blank.
- Admin → Finance → Parcel Claims: opens, empty.
- Admin → Parcel Packs: opens, empty.
- Admin → Customers → any customer: "Send parcels: Allowed" with a Block button.
- `/parcel` as a customer: looks the same as before (no extras while the settings are blank).

## Admin settings to do after deploy (owner) — all optional, blank = off
- Parcel Settings: most drop points + extra drop fee; return trip %; protection %, min/max fee, max cover, claim
  days; cash on delivery highest amount; keep safety photos (days).
- Refund Controls → Parcel protection claims: franchise / HQ limits, two-person-above amount, escalate after hours.
  Blank limit = that level can't approve.
- Roles: give `parcel_claims.manage` (decide claims) and `parcel_claims.payout` (approve payouts) to the right roles.
  Super Admin has both.
- Parcel Packs: create packs only when ready to sell them.

## Walk-through with the test logins
Existing logins, no new ones: the test customer (`/login`) and `test.rider` (`/rider/login`)
(`php artisan test-accounts create --password=...` on the server, if not done).
1. As Super Admin set most drop points = 3, extra drop fee, return trip %.
2. As the test customer book a parcel with 2 drops + return. As `test.rider` accept it, pick up, deliver each drop
   with its own code, then finish the return with the sender's return code.
3. While it is on its way: send a chat message each way and check "Rider is … km" and Open map on the sender's page.
4. After delivery: rate the rider with stars; check the rating shows.
5. Block the test customer from parcels and check `/parcel` refuses; then Allow again.
6. Optional: switch protection on, book a protected parcel, report it damaged, approve it in Parcel Claims.

## Notes for the owner
- Cash-on-delivery money lands in the sender's 1CallFix wallet. Customers have no bank withdrawal yet.
- Parcel protection terms need a lawyer before going live.
