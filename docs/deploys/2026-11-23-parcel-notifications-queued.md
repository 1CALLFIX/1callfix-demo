# Release 2026-11-23.4 — a mail outage can no longer cancel a parcel booking

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `bffa9e5` (2026.11.23.3) |
| Full test suite | passed 5216 of 5216 (local, 2026-11-23); known failures: none |
| Composer | no |
| Migrations | none |
| Front-end build (steps 6–7) | no |
| Scheduled commands | none new |
| Version | `VERSION` 2026.11.23.4 |

## What's in it (plain words)
Found while taking the Stage 3 screenshots: the "order booked" email was sent inside the booking itself, so when the
mail server could not be reached the whole booking was undone and the customer saw `Connection to "1CallFix.com:587"
timed out.` The same on-the-spot sending was used for every parcel status message (rider assigned, arrived, picked up,
delivered, cancelled, refunded) and the recipient's delivery-code SMS. The rider-side messages were already caught and
only logged, but the booking message was not.

- Parcel status messages and the recipient SMS now go to the **queue worker**, and only **after** the change is saved.
  A mail or SMS outage delays the message; the booking, accept, pickup and delivery always go through.
- Each channel (email, SMS, push) is its own queued job, so a broken mail server no longer holds up the SMS either.
- Needs the queue worker running (it already runs `onecallfix-worker`); messages arrive within seconds.
- Test: `test_a_mail_server_that_is_down_does_not_stop_a_booking` reproduces the exact error and fails without the fix.

Not changed here (same risk, separate release if wanted): other modules whose customer messages are still sent on the
spot (shop, hotel, hall, property, rental, events, taxi, payment and refund messages).

## Step 5 — migrations that must be pending (exactly these)
- none

## Step 9 — worker restart: REQUIRED (the worker must load the new notification code)

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Book a parcel as a test customer (or watch the next real one): the booking page opens the order straight away; the
  "Parcel order confirmed" email / SMS arrives a few seconds later.
- `php artisan queue:failed` shows no new ParcelOrderStatusNotification failures.
