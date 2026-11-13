# Release 2026-11-13 (7) — Function halls R1: owner sign-up, hall portal, admin approval

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `d58e17a` |
| Full test suite | 4837 passed of 4838; known failures only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (new portal pages) |
| Version | `VERSION` file bumped to `2026.11.13.7` |

## What's in it
Owner 2026-11-13: "start with events and then start with rentals". Function halls first, as their own module
(`halls`, "Function Halls"), shown under Rental in admin. Customers cannot book yet (R2).
- /partners card "Function hall owner" (Opening soon until Hall Settings → "Take function hall sign-ups" is on) → the
  one partner sign-up (hall name) → a draft hall + the hall owner documents list.
- Hall portal `/venue`: Home (each hall, status, checklist), hall editor (kind, seating / standing, AC, parking, rooms,
  facilities, about, address + map pin, cover + up to 10 photos, rules, refundable deposit, GST number, slots: Full
  day / Morning / Evening with time and price, slots past midnight allowed), "Send for review", Dates (block a whole
  day or one slot already sold outside 1CallFix), add another hall.
- A live hall: prices, slots, capacity, facilities, rules, deposit change at once; name, address, map pin, photos and
  description wait for the team (hall stays bookable; the public link never changes). Owner can switch bookings off.
- Admin → Rental → Function halls: approve / send back (reason shown to the owner) / approve or refuse a waiting
  change / feature / switch off; scoped by `halls.manage` (Super Admin + every role that approves hotels).
- Admin → Rental → Hall Settings (Super Admin only): sign-ups, kinds of hall, facilities, advance 25%, balance 7 days
  before, owner accepts within 2 h, book 1–365 days ahead, unpaid hold 15 min, commission 10%, booking fee (blank),
  payout 24 h after, deposit back 48 h after, cancellation (≥30 days full less ₹500, ≥7 days half the advance),
  GST optional (blank = none).
- Dashboard tile "Function halls" once one is approved.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_15_100000_create_halls_module` (halls, hall_slots, hall_blocked_dates; modules row `halls` not implemented;
  'hall' documents; `halls.manage` permission)

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- none new

## Test login
`php artisan test-accounts create --password=...` adds **Test Hall Owner** (`test.hall@1callfix.test`, 6000000114):
an approved, switched-on "Test Function Hall" (400 seats, AC, 3 slots: Full day ₹60,000 / Morning ₹35,000 / Evening
₹40,000, deposit ₹10,000). Walk-through: sign in at the partner sign-in → /venue → open the hall → change a price
(at once) and the name (waits) → Admin → Rental → Function halls → Changes waiting → approve → Dates → block a day.
`test-accounts disable` switches the hall off.

## Browser checks after deploy
- Admin → Rental shows Function halls and Hall Settings; Admin → Modules lists Function Halls (Not yet built).
- /partners shows "Function hall owner" (Opening soon).

## Admin settings to do after deploy (owner)
- Optional now: Hall Settings → "Take function hall sign-ups on /partners" to start collecting halls before R2.
