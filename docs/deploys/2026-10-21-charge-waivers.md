# Release 2026-10-21 — charge waivers, role permissions, franchise earnings, test accounts

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `2edcfa9` |
| Full test suite | 3933 of 3935 at `511de53`; the alert-count test was then updated (18/18 pass). Remaining known failure: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local `.env`, see docs) |
| Composer | composer.lock **not** changed → skip `composer install` |
| Front-end build (steps 6–7) | **no** — the built CSS/JS is identical to what is live (`app-Bx7ccz3E.css`). Skip steps 6 and 7. |

## What's in it
- `php artisan test-accounts create|list|disable --password=...`: one test login per account type (no test Super Admin).
- F1/F2: waiving a customer charge (cancellation charge, admin-cancel fee, lowered disputed work amount) now follows
  the manual money approval model: permission `bookings.waive_charge`, limits, maker-checker, **Charge Waivers** queue,
  escalation.
- Roles & Permissions: Super Admin edits what each role can do and creates new roles.
- Finance → **Franchise Earnings** page; the Franchise Owner role gains "View commissions".

## Step 5 — migrations that must be pending (exactly these)
- `2026_10_21_100000_create_charge_waivers_table`
- `2026_10_21_110000_grant_commissions_view_to_franchise_owner`

Rollback count for `migrate:rollback --step=`: `2`

## Step 10 — scheduled commands that must be listed
- `waivers:escalate` (new, every 15 minutes)
- `dispatch:sweep-deadlines`, `referrals:expire-balance` (still there)

## Browser checks after deploy
- Finance menu shows **Charge Waivers** and **Franchise Earnings**; both pages open.
- Refund Controls has a **Charge waivers (₹)** card.
- Roles & Permissions: each role (except Super Admin) has **Edit permissions**; there is a **+ New role** box.
- Open any booking: the page loads; the cancel box still works.

## Admin settings to do after deploy (owner)
1. Refund Controls → **Charge waivers**: set the franchise limit, HQ limit, second-approval amount and escalation hours.
   Until then only Super Admin can waive a charge.
2. Roles & Permissions: give "Waive a customer charge" to the roles that should have it (e.g. a new "HQ Finance" role).
3. Test logins, on the server (choose a strong shared password, not `password`):
   ```bash
   cd /home/1callfix.com/public_html && php artisan test-accounts create --password='YOUR-TEST-PASSWORD'
   ```
   Before launch: `php artisan test-accounts disable`.
