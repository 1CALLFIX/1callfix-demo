# Release 2026-11-13 (4) — Events E2 + E3: buy tickets, QR tickets, gate scanner, cancel + refunds, organiser payout

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `494c612` |
| Full test suite | 4820 passed of 4821; known failures only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (customer event pages, QR chunk from npm `qrcode-generator` 1.4.4 — local build only) |
| Version | `VERSION` file bumped to `2026.11.13.4` |

## What's in it
- Customers: `/{city}/events`, the event page, buy tickets online (Razorpay) or from the wallet (never cash, no
  benefits), one QR ticket per ticket on `/events/orders/{code}`, "My tickets" in the account menu, Events door on the
  homepage and city page, sitemap. No payment, no ticket: unpaid orders expire after Event Settings' hold.
- Organisers: Sales on each event, Gate scanner (phone camera on Chrome Android, or type the code; each ticket once),
  Cancel event (reason) → every buyer refunded in full + message.
- Admin: Sales on the event view, Cancel event (reason). Dashboard: Events in sales by module (paid orders only).
- Buyers may cancel only if Event Settings allows it (off by default); the booking fee is kept.
- Organiser payout: `events:complete` (hourly) finishes events past the payout hold (48 h) and credits each paid
  order's organiser share to the organiser's wallet (commission 10% unless negotiated; GST stays with 1CallFix when it
  collects GST; booking fee is 1CallFix's; franchise revenue share as usual).
- Hotel / Event Settings pages: their own intro line (they showed the shop's).

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_14_110000_create_event_orders_and_tickets` — **widens `payments.purpose`**. Before migrating, run
  `SHOW COLUMNS FROM payments WHERE Field = 'purpose'` and confirm it is exactly these 12 values: booking,
  wallet_topup, plan_subscription, parcel_order, taxi_ride, property_reservation, marketplace_order,
  rental_reservation, hotel_reservation, booking_bundle, cancellation_fee, parcel_pack.
- `2026_11_14_120000_add_event_payout_and_cancellation`

Rollback count for `migrate:rollback --step=`: `2`

## Step 10 — scheduled commands that must be listed
- `events:expire-unpaid` (every minute)
- `events:complete` (hourly)

## Browser checks after deploy
- Admin → Modules: Events can be switched on (do not switch it on for customers until the settings below are done).
- Admin → Events & organisers → an event → Sales card shows.

## Admin settings to do after deploy (owner)
- Event Settings → GST bands (check with your CA). Blank = no ticket can be sold.
- Optional: booking fee per ticket; buyers may cancel (off by default).
- Admin → Modules → switch Events on for Nellore when the first real event is approved.

## Test walk-through (test.organiser + test.customer)
1. Admin → Events: approve "Test Year End Bash". 2. Switch Events on for the test franchise. 3. As test.customer buy
   1 ticket (wallet). 4. As test.organiser open the event → Open gate scanner → scan / type the code (green), again
   (red). 5. Cancel the event → the customer's wallet is refunded in full.
