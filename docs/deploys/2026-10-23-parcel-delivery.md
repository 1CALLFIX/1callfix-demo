# Release 2026-10-23 (3) — Parcel delivery, complete (switch on per franchise in Modules)

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `d330232` (check with `git log -1` on the server) |
| Full test suite | 4028 of 4029 pass (re-run on the merged commit). Only failure: the known `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local `.env`) |
| Composer | composer.lock changed: no |
| Front-end build (steps 6–7) | **yes** (new customer, rider and admin pages) |
| Version | `VERSION` file = `2026.10.24.1` (shipped 2026-10-24) |

## What's in it
Owner decisions 2026-10-23: riders work in a web area, price = distance + weight + size, pay online / wallet / cash,
deliver to anyone.

- **Modules screen:** Parcel Delivery now shows **Implemented** and can be activated per country / city / zone /
  franchise. It stays **Inactive everywhere** after deploy until you switch it on. While it is off, the Send a Parcel
  page returns 404, the home tile and menu link are hidden, and riders get no offers.
- **Customer web:** `/parcel` (Send a parcel: pickup from a saved address, map search or current location; drop by
  map search or a saved address; recipient name + phone; size, weight, note; live price with breakdown; pay online,
  wallet or cash). `/parcels` (list) and `/parcels/{id}` (tracking: progress, pickup + delivery codes, rider, Pay now,
  cancel until pickup, history). "Send a parcel" tile on the home page and "My parcels" in the account menu.
- **Fast booking (owner 2026-10-24: "2 or 3 taps"):** repeat send = home tile → "Send again" chip → **Book now**
  (3 taps). Pickup defaults to the default address, or GPS fires on its own when there is none. The last size and
  payment method are remembered. "Choose from contacts" fills the recipient in one tap (Android Chrome).
  Recipient name is optional. Weight, contents and note are folded into "More details". Price + Book stay pinned
  above the phone's bottom menu.
- **Online payment:** Razorpay through the existing webhook. An online order is **not sent to riders until the
  payment is captured**. Unpaid orders are cancelled after `parcel.unpaid_expiry_minutes` (default 30). A payment that
  lands after cancellation is refunded automatically.
- **Cash:** the rider collects at pickup (their screen says so). The rider keeps the cash and **owes the platform and
  franchise share** (new rider cash dues), recovered from their wallet when online earnings arrive or when they press
  Settle. Before this, a cash parcel would have credited the rider's wallet as well (paid twice).
  Field-worker payouts are refused while dues remain. Optional dues limit stops cash offers to that rider.
- **Thumb rule:** parcel offers no coupon, discount or credit of any kind. A coupon code is refused for every payment
  method (tests prove it).
- **Pay at: Pickup | Drop (owner 2026-10-24):** when Cash is chosen, a Pickup / Drop toggle appears. Drop = the
  recipient pays the rider cash at the door. Nothing waits for the recipient: the order goes to riders at once. The
  rider's screen says "collect from the recipient"; the cash is recorded at delivery and becomes a rider cash due,
  exactly like cash at pickup. If the recipient refuses to pay, the rider calls the sender, who pays the rider on the
  spot (UPI or cash); the rider taps **Sender paid me** and delivers as normal (no return trip, no second charge; the
  sender is told). Customer API: `cash_collect_at` = `pickup` (default) or `drop` on create, returned on every order.
- **Rider web area:** `/rider/login`, `/rider` (go online with location, ringing offer alert, accept / decline, on my
  way, pickup code, delivery code, directions and call), `/rider/history`, `/rider/earnings` (wallet, cash dues,
  settle). Offers need a fresh location (same staleness setting as providers).
- **Recipient SMS:** when SMS is one of the location's notification channels, the recipient gets the delivery code by
  SMS once a rider accepts. The sender always sees both codes on the tracking page.
- **Admin:** Admin → Workers → **Add parcel rider** (until now nothing could create a rider). Parcel Orders: recipient,
  distance, price breakdown, payment state, and **Assign a rider** by hand (permission `parcel_orders.reassign`).
  New **Parcel Settings** screen (super admin): pricing, limits, unpaid expiry, dues limit, rider dispatch, with a
  price check.
- **Addresses:** pins typed on the parcel page are stored as parcel-only addresses and never appear in the address
  book. An address used by a parcel order cannot be deleted (it would have deleted the order).
- Customer API: `POST /api/parcel-orders/{id}/pay`; quote returns distance, breakdown and payment methods; create
  takes `recipient_name`, `recipient_phone` (required) and pickup contact fields.

## Step 5 — migrations that must be pending (exactly these)
- `2026_10_23_200000_complete_parcel_delivery` — adds 7 nullable columns to `parcel_orders`, `addresses.is_parcel_only`
  (default false), creates `field_worker_cash_dues`, sets `modules.is_implemented = 1` for `parcel`. No enum widened.
- `2026_10_24_100000_add_cash_collect_at_to_parcel_orders` — adds `parcel_orders.cash_collect_at` (string, default
  `pickup`) and `parcel_orders.recipient_refused_at` (nullable). No enum widened.

Rollback count for `migrate:rollback --step=`: `2`

## Step 10 — scheduled commands that must be listed
- `parcel:expire-unpaid` (every 5 minutes)

## Browser checks after deploy
- Admin → Modules (franchise NELLORE): Parcel Delivery shows **Implemented / Inactive** with an Activate link.
- Admin → Other Verticals → **Parcel Settings** opens; Parcel Orders opens.
- `/parcel` returns 404 while parcel is inactive everywhere (expected).
- Home page shows no "Send a parcel" tile yet.
- `/rider/login` opens.

## Admin settings to do after deploy (owner) — in this order
1. **Parcel Settings** (global or per franchise): base fare, km included, rate per km, longest delivery, heaviest
   parcel (all required — parcel cannot be booked until they are set). Optional: weight rate, size surcharges, road
   factor (e.g. 1.3), unpaid expiry, rider cash dues limit, dispatch timings.
2. **Workers → Add parcel rider** for each rider (zone, mobile, password; confirm ID + licence checked).
3. **Modules → Parcel Delivery → Activate** for the franchise(s) where you want it live. The screen **refuses** until
   steps 1 and 2 are done for that location (owner rule 2026-10-24: riders are onboarded before parcel goes live);
   the Parcel row shows "N riders onboarded · ready / not ready to activate". Deactivating is always allowed.
4. One test parcel end to end (cash, then online) with a rider logged in at `/rider`.
