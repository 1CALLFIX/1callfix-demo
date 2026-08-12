# QA Data Integrity Report

Evidence from running the new `qa:seed`/`qa:clean` factory (commit `4c1b424`) on an isolated SQLite database on the production server's own filesystem — never against production data. Two runs performed: `--scale=small` (fast iteration, 20 bookings) and `--scale=default` (the literal targets from the finalization sprint, 200 bookings). All numbers below are from the `--scale=default` run unless noted.

## Dataset produced (`--scale=default`)

776 records across 27 directly-created tables in 5.5 seconds, plus real side-effect records from the Actions/Services invoked:

| Category | Count | Notes |
|---|---|---|
| Countries / Cities / Zones / Franchises | 3 / 9 / 27 / 9 | Mixed HQ vs. franchise-operated (owner assigned on the first franchise) |
| Admin users | 7 | One per system role, each with a real `role_assignments` row at the appropriate scope |
| Customers | 50 | Plus 6 business-account owners |
| Providers | 20 | Mix of `independent`/`company`, ~10% `kyc_status=pending`, ~7% inactive — deliberately not all "happy path" |
| Workers (`field_workers`) | 40 | Platform-direct (6), multi-partner (2), multi-capability (8), zero-capability/mismatch (2), inactive (3) — every case the sprint brief names |
| Bookings | 200 | Across all 7 FSM-reachable statuses (see below) |
| Subscriptions | 30 | active (14), paused (6), cancelled-pending-expiry (6), scheduled-upgrade (4) |
| Commissions | 80 | Exactly one per completed booking |
| Wallets / Wallet transactions | 19 / 85 | |
| Loyalty points rows | 160 | |
| Referrals | 5 (3 rewarded, 2 pending) | |
| Dispatch attempts | 82 | 5 notified, 77 timed out (realistic — QA candidates/geography don't always produce an acceptance) |
| Booking status history | 642 | |

## Booking FSM coverage

| Status | Count | Reachable via |
|---|---|---|
| pending | 20 | `CreateBookingAction` only |
| searching_provider | 20 | + one real `ServiceMatchingJob::handle()` round |
| assigned | 20 | + `AdminReassignBookingAction` (deterministic; natural dispatch depends on geographic matching succeeding for synthetic coordinates, which isn't guaranteed — this is the same real action an admin uses from the live queue, not a shortcut) |
| in_progress | 20 | + `StartBookingAction` with the real generated OTP |
| on_hold | 10 | + `PlaceBookingOnHoldAction` |
| completed | 80 | + `CompleteBookingAction` with the real OTP — commission/wallet/loyalty/referral all fire for real |
| cancelled | 30 | + `AdminCancelBookingAction` |
| **provider_en_route** | **not seeded** | **No implemented Action anywhere in this codebase ever transitions a booking into this status — confirmed by grep across `app/Actions` and `app/Http/Controllers`.** |
| **disputed** | **not seeded** | **Same — no implemented Action ever sets this status.** |

These last two are real architectural findings, not a QA gap: both are valid `bookings.status` enum values with zero implemented code path that produces them today. Fabricating a booking in either state via a raw status write would misrepresent something the real system never actually does. This should be tracked as either [FUTURE] (a Start-in-transit step and a dispute-resolution flow are both plausible near-term additions) or [OPEN BUSINESS DECISION] (whether "provider en route" needs its own explicit step distinct from "assigned", and what a dispute workflow should actually do) — not silently ignored.

## Financial reconciliation

- **Commissions:** 80 completed bookings → 80 commission rows. Zero duplicates. Zero commissions without a matching booking.
- **Wallets:** 19 wallets, each independently reconciled as `SUM(wallet_transactions WHERE status=successful, credit - debit) == wallets.balance`. **0 mismatches.** 0 negative balances.
- **Subscriptions → entitlement balances:** 0 subscriptions with zero entitlement balance rows (every active subscription actually got what its plan promised).
- **Referrals:** 3 rewarded, 0 duplicate `referred_id` (DB-unique-backed).

## Worker/Partner relationship coverage

All required scenario types from the sprint brief are present in the seeded data: platform-direct workers (6, zero `partner_workers` rows), multi-partner workers (2, 2+ active links), multi-capability workers (8), a capability-mismatch case (2 workers with zero granted capabilities, deliberately), and inactive workers (3).

## Orphan sweep (7 relationship checks, same method as the production sweep)

`bookings.customer_id`, `bookings.provider_id`, `bookings.assigned_worker_id`, `partner_workers.provider_id`, `partner_workers.field_worker_id`, `subscriptions.plan_id`, `entitlement_balances.subscription_id` → **0 orphans on all 7.**

## Bugs found and fixed while building this factory

1. **`OrderCodeService` was MySQL-only** (raw `ON DUPLICATE KEY UPDATE`/`LAST_INSERT_ID()`), which blocked booking creation — and therefore the entire QA factory — from running on anything but a live MySQL database. Fixed to be driver-aware: MySQL's exact original SQL is untouched; SQLite gets an equivalent `lockForUpdate()`-based path using this codebase's own dominant race-safety convention. See commit `025b001`.
2. **Seeder-side bug, not a backend bug:** `DispatchService::hasSkill()` matches `provider.skills` against actual `service_categories.id` integers — the seeder initially populated string labels, silently producing zero real dispatch matches. Fixed; `dispatch_attempts` went from 0 to 82 after the fix.

## Known, honest gap: `payments` table stays empty

`payment_method = 'online'` bookings never get a `payments` row until a real Razorpay checkout completes and its webhook fires — that's the existing, correct production behavior, not something this factory works around. QA plans are seeded at `price = 0` for the same reason: SubscriptionService's free-tier activation path is fully real (not a QA-only shortcut), while a paid-plan purchase genuinely cannot complete without live gateway credentials, which this environment doesn't have and this program will not fake. **Paid-plan purchase and the online-payment-capture path remain unexercised by this QA dataset** — a real, named limitation, not a silently-skipped one.

## Cleanup verification

`qa:clean` deleted exactly 1,844 rows across 36 tables (children before parents throughout, derived from the manifest's tracked root IDs — not a cascade-delete assumption or a naming-convention guess). A full 36-table emptiness check immediately after confirmed **zero remnants**. Repeated at `--scale=small` beforehand with the same result. The production-safety guard on both `qa:seed` and `qa:clean` was directly triggered (temporarily setting `APP_ENV=production` on the isolated checkout) and confirmed to refuse with exit code 1 in both cases, then reverted.
