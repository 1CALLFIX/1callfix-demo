# Release 2026-11-23.3 — Parcel Stage 3: goods vehicles (3-wheeler, mini truck, pickup, truck) + helpers

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `6f283b3` (2026.11.23.2) |
| Full test suite | passed 5214 of 5214 (local, 2026-11-23); known failures: none |
| Composer | no |
| Migrations | 1 (below), additive, no enum widened |
| Front-end build (steps 6–7) | **yes** (new colours on the vehicle cards) |
| Scheduled commands | none new |
| Version | `VERSION` 2026.11.23.3 |

## What's in it (plain words)
Owner 2026-11-23: "add the features of the Porter app", starting with bigger vehicles and helpers. Built on the parcel
engine already live (booking, price, rider matching, payment, OTP, chat, rating), so it adds no new background job and
nothing extra for customers to download.

- **Vehicles**: two-wheeler (as today), 3-wheeler (500 kg), mini truck (7 ft, 750 kg), pickup truck (8 ft, 1,500 kg),
  truck (14 ft, 2,500 kg). Each has its own fare card in **Admin → Parcel Settings → "Vehicle: …"**: base fare,
  distance included, rate per km, heaviest load, longest trip, price per helper, free loading time, waiting charge.
  **A vehicle is offered only where its base fare AND per-km rate are set.** Nothing changes for customers until then.
- **Booking page `/parcel`**: once a second vehicle is priced, colourful vehicle cards appear (emoji, size, the price
  for this trip on each card). A goods vehicle hides the parcel size chips, asks "What are you moving?" (Furniture,
  Appliances, House shifting, Shop goods, Building material, Groceries, Other), and offers helper chips
  "No helper / + 1 helper / + 2 helpers" with their price, where the helper price is set.
- **Helpers** are one line on the bill ("2 helpers for loading"), paid with the trip; the driver sees
  "Bring 2 helpers for loading" on the current job.
- **Drivers** join on the same rider sign-up: "Which vehicle do you drive?" chips, plus the number plate (required
  for a goods vehicle). They are only offered orders for their own vehicle. Drivers can join before the vehicle is
  priced (supply first). Admin → Partners → People → Review shows and changes the vehicle and plate (audit-logged).
- **Customer order page** says "Your driver", shows the number plate, and "🚚 Mini truck · 2 helpers".
- **App API**: `POST /api/parcel-orders/quote` and `/api/parcel-orders` accept `vehicle_type` and `helpers`; the quote
  returns `vehicle_options` (vehicles priced here, with `max_weight_kg` and `helper_fee`) and `helpers_max`. Omitted =
  two-wheeler, so older app builds keep working.
- **Driver words**: on a goods vehicle order the customer sees "driver" everywhere (status, chat, rating, codes), and the
  driver's offer and job carry a green **Goods trip** badge instead of Parcel.
- **Whole rupees (owner 2026-11-23)**: every parcel and vehicle price line is rounded to the nearest rupee (₹572, not
  ₹572.44), **two-wheeler parcels included** (at most 50 paise up or down per line); the booking and order pages show
  no paise on whole amounts.
- **Rules kept**: parcel packs (a benefit) cover two-wheeler parcels only; coupons stay refused; parcel itself still
  needs the two-wheeler prices and two-wheeler riders to be switched on (truck drivers do not count as ready riders).
- **Not built (on purpose)**: tolls and night charge (Nellore city trips rarely meet a toll; they come with intercity),
  Packers & Movers, live map, business accounts (later stages).

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_23_300000_parcel_stage3_vehicles` (adds `parcel_orders.helpers`, `field_workers.vehicle_number`)

Rollback count for `migrate:rollback --step=`: 1

## Step 10 — scheduled commands that must be listed
- none new

## Test login and walk-through (THUMB RULE: test login per module)
`php artisan test-accounts create --password=...` now also makes **test.truckdriver@1callfix.test** (Test Truck
Driver, approved, mini truck AP 26 TT 0001, signs in at `/rider/login`).

Walk-through (needs the mini truck priced, see below):
1. As test.truckdriver: sign in at `/rider/login`, go online, share location.
2. As test.customer: `/parcel` → mini truck card shows a price → tap Mini truck → "+ 1 helper" → Furniture → book (cash).
3. Driver gets the offer "Mini truck · 1 helper", accepts, sees "Bring 1 helper for loading".
4. Customer's order page shows "Your driver" and the plate AP 26 TT 0001. Finish with the pickup and delivery codes.
5. Check that test.rider (two-wheeler) was NOT offered the truck order.

## Browser checks after deploy
- Admin → Parcel Settings shows 4 new groups "Vehicle: 3-wheeler / Mini truck / Pickup truck / Truck", all blank.
- `/parcel` as a customer looks as before (no vehicle cards while the vehicles are blank), with whole-rupee prices.
- `/partners` → delivery rider sign-up shows "Which vehicle do you drive?".
- Admin → Partners → People → any rider → Review shows Vehicle with a Save button.

## Admin settings to do after deploy (owner) — all optional, blank = off
- Parcel Settings → **Vehicle: Mini truck** (start with one): base fare, distance included, rate per km; optional
  price per helper, free loading time, waiting charge. Set them at Nellore franchise or zone level.
- Switch a vehicle on only when 3–5 approved drivers of that vehicle are in the city.
- Partner documents: consider making "Driving licence" and "Vehicle registration document" required for riders
  (Admin → Partner documents).
