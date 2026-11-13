# Release 2026-11-13 (10) — Function halls R4: damage claims on deposits (money approval model)

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `84e646c` (halls R3) |
| Full test suite | 4863 passed of 4864; known failures only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (new admin screen, owner form) |
| Version | `VERSION` file bumped to `2026.11.13.10` |

## What's in it
- Owner: "Something damaged? Hold the deposit" now also asks how much of the deposit they want (pre-filled with the
  whole deposit). The deposit stays held and a claim waits for the team.
- Admin → Rental → **Damage claims**: one card per claim (hall, booking, customer, the owner's words, deposit, amount
  asked, age, escalation). Type what the hall gets → "Hall gets ₹X · Customer gets back ₹Y" → reason → Decide.
- MANUAL MONEY ACTIONS approval model: permission `hall_damage.payout` (Super Admin by default; assign to roles in
  Admin → Roles), franchise / HQ limits on the hall's part, a second different approver above a threshold, Reject
  sends it back, Retry after a failed payment, escalation every `refund.hall_damage.escalate_after_hours`, reason at
  every step, activity log. Settling: the customer's part back to where they paid (card / UPI / wallet), the hall's part
  into the owner's wallet (paid out with earnings). Nothing is automatic.
- Admin → Refund Controls: "Hall damage claims (₹)" limits next to the other money rules.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_15_130000_create_hall_damage_claims` (hall_damage_claims; permission hall_damage.payout → Super Admin)

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- `refunds:escalate-hall-damage` (every 15 minutes)

## Admin settings to do after deploy (owner)
- Refund Controls → Hall damage claims: franchise limit, HQ limit, second approval above, escalate after (blank = only
  Super Admin decides, no second approval, no escalation).
- Then Admin → Modules → Nellore → Function Halls → Activate when ready; Hall Settings → "Take function hall sign-ups".
