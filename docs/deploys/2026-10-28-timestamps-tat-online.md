# Release 2026-10-28 — Created / Completed / TAT on every admin list; Online column; Parcel — Today

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1). Also carries the undeployed `2a40770` (one account, many partner roles — see `2026-10-27-one-account-many-partner-roles.md`, no migrations) |
| Live before | `cce5128` (check `git log -1` on the server; stop if different) |
| Full test suite | 4141 of 4142 pass; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (new Tailwind classes in the date / TAT / online components) |
| Version | `VERSION` file bumped to `2026.10.28` |

## What's in it
Owner request 2026-10-28.
- **Dashboard**: "Other Verticals — Today" replaced by **Parcel — Today** (Orders, Live now, Delivered, Cancelled,
  Revenue, Avg delivery TAT; link to Parcel Dashboard). Pre-launch verticals stay as one slim chip row.
  Recent Bookings gains Created / Completed / TAT.
- **Every transaction list: Created | Completed | TAT** — full date + exact time, close time with outcome
  (Completed / Cancelled / Rejected …, amber "In progress" while open), TAT pill (green done, grey cancelled,
  amber "so far" while open). Bookings, Parcel orders, Taxi rides, Marketplace orders, Property / Rental / Hotel
  reservations, Payments, Payouts, Wallet ledger, Mismatch refunds, Booking disputes, Charge waivers
  (queues: "Age / TAT"), KYC support requests, customer booking history. Bookings CSV adds `closed_at`, `tat_minutes`.
- Ledger lists (commissions, loyalty, referrals, flash-sale redemptions, franchise earnings, earnings ledger,
  notification logs, partner leads): full date + exact time.
- **People lists: full joined / applied date + time and an Online column** (All Users, Customers, Providers,
  Partners both tabs, Workers / riders). Online = active in the last 5 minutes (new `users.last_seen_at`, written
  after the response at most once a minute, web + API); partners also show On duty / Off duty.
- Not admin-switchable yet: the columns always show; the 5-minute window is `User::ONLINE_WINDOW_MINUTES`.

## Step 5 — migrations that must be pending (exactly these)
- `2026_10_28_100000_add_last_seen_at_to_users_table`

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Admin dashboard: "Parcel — Today" panel with six cards; slim "Pre-launch today" chip row; Recent Bookings shows
  Created / Completed / TAT (e.g. a completed booking shows a green TAT pill, a cancelled one a grey one).
- Admin → Bookings, Parcel Orders, Payments, Payouts, Wallet Ledger: the three columns, dates in IST.
- Admin → Customers / All Users / Providers / Partners: Joined / Applied with full date + time; Online column —
  your own admin account shows green "Online" in All Users → Staff / Admin; others "Not seen yet" until they next
  use the app.
- Plus the browser checks in `2026-10-27-one-account-many-partner-roles.md`.

## Admin settings to do after deploy (owner)
- none
