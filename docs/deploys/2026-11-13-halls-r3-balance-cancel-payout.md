# Release 2026-11-13 (9) — Function halls R3: balance, cancellation, owner payout, deposit return

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `3c4c46c` (halls R1 + R2) |
| Full test suite | 4857 passed of 4858; known failures only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (booking page and owner bookings changed) |
| Version | `VERSION` file bumped to `2026.11.13.9` |

## What's in it
- **Balance:** one big "Pay balance ₹…" button on the customer's booking page (online or wallet); balance + deposit.
  Reminders from 3 days before the due date, once a day. Still unpaid 2 days after the due date → cancelled under
  the normal cancellation rule (the owner keeps what the rule gives).
- **Customer cancels:** the page shows "If you cancel now, you get back ₹…" before the button. Rule (Hall Settings):
  ≥30 days → everything less ₹500; ≥7 days → half the advance kept; closer → the advance kept. The deposit always
  comes back. Waiting for the hall to accept → everything back.
- **Owner cancels** (Bookings → Cancel this booking, with a reason): the customer gets everything back.
- **After the event** (hourly `halls:after-events`): completed + the owner paid after commission (Hall Settings'
  payout hours); money a cancellation left for the owner paid the same way; the deposit goes back after Hall
  Settings' hours unless the owner tapped "Something damaged? Hold the deposit".
- Owner Bookings: "✓ Fully paid" / "⏳ Balance due by …" on each booking. Admin → Hall bookings: deposit status,
  damage note, "Damage reported" tab.

Not yet (R4): the team deciding a damage claim (how much of the deposit goes to the hall) under the money approval
rules. Until then a reported deposit stays held.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_15_120000_add_hall_balance_cancel_payout` (hall_bookings balance / deposit / cancel columns;
  commissions.hall_booking_id)

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- `halls:after-events` (hourly)

## Admin settings to do after deploy (owner)
- none. Function Halls can be switched on (Admin → Modules) once R4 is in, or now if damage claims can wait.
