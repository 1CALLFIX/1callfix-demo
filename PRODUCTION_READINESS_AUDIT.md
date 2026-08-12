# 1CallFix — Production Readiness Audit

**Baseline:** commit `9e8dd98` on `main`, 2026-08-12 (updated in place across two consecutive work programs the same day — originally written at `939b270`). **Verdict: NOT READY** — see §28. This document reports only what was actually inspected or tested this session; anything not reached is labeled NOT AUDITED, not silently assumed clean.

---

## 1. Executive Summary

The backend, database, and RBAC layer for the Service vertical have real, tested foundations: **121 automated tests (308 assertions)** exist and pass, covering authorization enforcement (including real HTTP-level API tests, not just Action-layer calls), financial idempotency, worker delegation, booking state transitions, dispatch race safety, loyalty/referral, and a Phase-A-safe Plan Engine smoke test. A read-only integrity sweep of production found zero orphaned records or constraint violations; a second, much larger sweep against a real 776-record QA dataset (200 bookings, 80 completed, 30 subscriptions) built by a new `qa:seed`/`qa:clean` factory also found zero issues. Four real bugs were found and fixed via this work, not just written and assumed correct: a flaky test fixture, a `RazorpayService` constructor crash on any environment without Razorpay credentials configured, an `OrderCodeService` MySQL-only dependency that blocked the QA factory entirely, and — most significantly — **a live authorization gap in `Franchises\Manage`** where `update()`/`toggleStatus()`/`deleteFranchise()` had zero permission check (any authenticated admin-panel actor could edit commission rates, toggle status, or delete a franchise).

However, this is a *backend and data-integrity* readiness baseline, not a full-system one. The Admin UI has no design system (24 screens, no shared components), no API audit was performed, no printing system exists, no dedicated QA web app exists, and no browser-driven end-to-end test was run. These are not minor gaps — they are named, sized pieces of work that were not attempted this session, not silently skipped.

## 2. Architecture

Actor model (Customer/Partner/Worker) confirmed distinct and correctly separated: `providers` (accountable business) and `field_workers` (field execution) are independent tables, linked via `partner_workers`, matching the approved architecture. `bookings.provider_id` (accountable Partner) and `bookings.assigned_worker_id` (actual Worker) confirmed to serve their distinct roles by direct test (`AssignBookingToWorkerActionTest::test_assignment_does_not_change_provider_id_or_booking_status`).

## 3. Database

122 migrations, all verified to run cleanly from scratch (fixed a real portability blocker this session — see `PROJECT_CURRENT_STATE.md` §16). Read-only integrity sweep against production (this session) checked 24 orphan/consistency conditions across `bookings`, `commissions`, `wallets`/`wallet_transactions` (full ledger reconciliation, not just balance inspection), `field_workers`/`partner_workers`/`field_worker_capabilities`, `subscriptions`/`entitlement_balances`, `loyalty_points`, `referrals`, `role_assignments`, `dispatch_attempts`, `booking_status_history`: **zero issues found.**

**Caveat that matters:** production carries almost no operational data (0 bookings, 0 commissions, 0 payments, 0 role_assignments, 2 users, 4 franchises as of this check) — a clean integrity sweep against empty tables is expected and is weak evidence on its own. It rules out schema-level defects; it does not demonstrate the system holds up under real transaction volume, which has not happened yet.

## 4. Security

**Bounded pass performed this session (not exhaustive, not OWASP-certified):**
- Mass assignment: no `$guarded = []` found in any model — every model uses explicit `$fillable`. [CHECKED — clean]
- SQL injection: no string-interpolated raw SQL found outside migrations (which use fixed, non-user-input strings). [CHECKED — clean]
- CSRF: standard Laravel `web` middleware group applies to all admin routes (`routes/admin.php` is `require`'d from `web.php`), not excluded anywhere in `bootstrap/app.php`. [CHECKED — clean]
- Admin route authentication: `auth` + a dedicated `EnsureHasAdminAccess` middleware gate every admin route, in addition to the Livewire-component-level RBAC checks — defense in depth, not RBAC-only. [CHECKED — clean]
- API authentication: `auth:sanctum` on the protected group. [CHECKED — clean]
- Razorpay webhook: unauthenticated route (correct — webhooks can't session-auth) but verified to call `verifyWebhookSignature()` before trusting any payload. [CHECKED — clean]
- RBAC/IDOR/scope isolation: the 7 previously-open gaps closed and tested this session (see `PROJECT_CURRENT_STATE.md` §13); the pre-existing critical `Roles::assign()/revoke()` escalation gap fixed and regression-tested.
- File uploads: consistent `mimes:png,jpg,jpeg`+size-limit validation pattern across Categories/Subcategories/Services/Banners. [CHECKED — clean, spot-checked, not exhaustively]
- API-layer IDOR: verified with real HTTP requests, not just code inspection — a worker hitting another worker's job id directly via `/api/worker/jobs/{id}/start` is rejected with 403 and the booking is left untouched (`WorkerJobApiTest`); a second provider racing to accept an already-assigned booking gets a clean 409, not a silent double-assignment (`DispatchApiTest`).
- **HIGH finding, fixed this session:** `Franchises\Manage::update()`/`toggleStatus()`/`deleteFranchise()` had zero permission check — any authenticated admin-panel actor could edit a franchise's commission rates, toggle it active/inactive, or delete it. Found while writing `RBAC_SCOPE_MATRIX.md`, fixed immediately (same `franchises.manage` check `save()` already had), 6 regression tests added. See commit `9e8dd98`.

**NOT AUDITED this session:** XSS (Blade's default escaping wasn't specifically stress-tested), rate limiting, session fixation/rotation specifics, path traversal beyond the upload validation spot-check, replay-attack windows beyond webhook signature verification, secrets-in-repo scan, dependency CVE scan. **No claim of a complete or certified security audit is made.**

**Findings by severity:** 1 HIGH found and fixed (`Franchises\Manage`, above). 0 unresolved CRITICAL/HIGH as of this commit. This reflects the *scope actually covered*, not a guarantee nothing exists in the uncovered areas.

**API duplicate-record gaps (not security holes, not fixed — flagged for a product decision):** `POST /bookings/{booking}/pay/create-order` and `POST /plans/{plan}/subscribe` don't check for an existing pending order/subscription before creating another. See `API_INVENTORY.md`.

## 5. RBAC

All 7 previously-open enforcement gaps closed and tested (44 tests). Scope resolution derived from schema, not guessed. Roles-escalation regression covered (6 tests). See `PROJECT_CURRENT_STATE.md` §13 for full detail.

## 6. Scope Isolation

Tested directly: franchise, city, and country scope boundaries for Zones; franchise-target scope for Banners; zone scope for Bookings creation. Cross-scope denial confirmed in each case (not just same-scope allow). Global-only enforcement confirmed correct for unscoped catalog/CMS data (no franchise column exists to partially scope against).

## 7. Booking FSM

16 tests covering Start/Complete/AdminCancel/AdminReassign transition boundaries: correct/wrong OTP, wrong provider, duplicate completion (confirmed does not double-apply commission), completed/cancelled bookings correctly reject further mutation, worker assignment cleared on reassignment to a different provider, approved-vs-rejected extra-work items correctly affect `price_final`.

## 8. Dispatch

4 tests, including a direct regression test for the previously-fixed race condition (`1e108ff`): a late re-dispatch round cannot clobber a real concurrent acceptance, cannot reopen a cancelled booking. **Caveat:** PHPUnit is single-threaded — this proves the fix's *outcome* (the row lock correctly serializes and re-checks state), not true multi-process concurrent timing. No load/stress test was run.

## 9. Worker

12 tests covering every boundary the makeover brief names: ownership, assignable-status window, active/inactive worker, active team-link required, capability match (including null-scoped capability). All passed on first run, confirming the existing implementation — not something this session had to fix.

## 10. Partner

Covered indirectly via the Worker and Booking FSM tests (Partner accountability for commission unaffected by worker delegation, confirmed by direct assertion). No dedicated Partner-perspective test suite (e.g., a Partner's own dashboard/financial view) was built.

## 11. Financial Integrity

One `WalletService`, one `CommissionService`, confirmed by inspection — no second implementation exists anywhere in `app/`. Commission idempotency backed by both application logic and a new DB-level `UNIQUE` constraint, both tested. Wallet ledger reconciliation (`opening + credits − debits = closing`, computed from `wallet_transactions`, not just current balance) checked against production: **zero mismatches found** (on a near-empty ledger — see the caveat in §3).

## 12. Plan Engine

**Phase A remains completely untouched** — zero lines of `app/Services/Plans/*` or the Phase A migrations were modified this session. A 6-test smoke suite was added that exercises the existing services through their public API only. The most significant one directly regression-tests the previously-fixed renewal-upgrade bug (`4f92fdf`) and confirms it remains fixed.

## 13. Loyalty

7 tests: earn/balance, idempotent duplicate-earn prevention, redeem-to-wallet at configured rate, over-balance and below-minimum redemption rejection, expired-points exclusion from balance.

## 14. Referral

7 tests: signup-creates-pending, no-referrer-is-noop, self-referral rejected, duplicate-referred-user rejected (DB-unique-backed), first-completed-booking qualifies and rewards, second booking does not double-qualify or double-credit.

## 15. Queue

`ServiceMatchingJob`'s race-safety confirmed by test (§8). Broader queue behavior (retry counts, `failed_jobs` table behavior under real failures, Supervisor restart handling) **NOT AUDITED** this session.

## 16. API

`routes/api.php` exists, Sanctum-protected, webhook signature-verified. **`API_INVENTORY.md` now exists** — all 24 routes tabulated (auth/ownership/validation/errors/side-effects/idempotency), read directly from all 9 controllers, not guessed. 14 real HTTP-level tests added this session for the two highest-value routes (`DispatchApiTest`, `WorkerJobApiTest` — accept/complete/start, cross-actor IDOR, race-to-accept, wrong/missing OTP). **20 of 24 routes still have zero HTTP-level test** — the underlying Action/Service is tested for most of them, but a route-level regression (broken middleware, wrong validation rule, wrong status code) wouldn't be caught. Two duplicate-record (not duplicate-money) gaps found and flagged, not fixed (real product decisions): repeat calls to `pay/create-order` or `plans/{id}/subscribe` don't check for an existing pending order/subscription first.

## 17. Admin UI

**Not touched.** Still 7 one-off Blade components, no shared design system, 24 screens each styled independently. This remains the single largest piece of unstarted scope — genuinely a multi-week build, not attempted this session with anything less than full honesty about that.

## 18. Printing

**Does not exist.** No print views, no PDF generation, for any document type. Not started.

## 19. QA Web App

**Does not exist.** No standalone QA frontend connected to the real backend was built. Not started. (A real, working QA **data** factory does now exist — see §8/§21 — but that's backend tooling, not a frontend validation client.)

## 20. Performance

**Not systematically profiled under load.** Index additions were usage-grep-justified, not live-profiled. **No separate MySQL QA database is available in this environment** (the shared-hosting DB user is locked to the production database only, confirmed by directly attempting `CREATE DATABASE` and getting `Access denied`) — this also means true multi-process concurrency load testing isn't meaningfully achievable here beyond what SQLite's single-writer model already forces (see §21's concurrency note). This is an environment constraint, not something skipped by choice.

## 21. Testing

**121 tests, 308 assertions, 0 failures**, verified stable across repeated runs including a fully fresh `git clone` + `composer install` + migrate + test cycle (not just re-running in a warm checkout). Executed against an isolated SQLite database on the production server's own filesystem — never against production data, never against local MySQL. See `FINAL_SYSTEM_TEST_MATRIX.md` for the itemized breakdown.

**Concurrency testing, honestly scoped:** the dispatch-race and accept-race tests prove the row-locking mechanism's *outcome* correctly (one winner, no corruption, verified via both a direct Action-layer test and a real HTTP race-to-accept test) — sequential execution that exercises the same code path two overlapping transactions would. **True multi-process concurrent load was not tested** — SQLite (the only isolated database available here) is single-writer by design, and no separate MySQL QA database could be provisioned (see §20). This is a genuine, named limitation, not papered over with a fake "concurrency test" that wouldn't actually prove anything stronger.

## 22. Data Integrity

Two independent sweeps, zero issues in either: (1) the original 24-check read-only sweep against production (near-zero real data, weak evidence on its own — see §3), and (2) a much stronger sweep against a real 776-record QA dataset built by the new `qa:seed` factory (200 bookings, 80 completed with exactly 80 matching commissions, 19 wallets all reconciling exactly, 0 orphaned FKs across 7 relationship checks). See `QA_DATA_INTEGRITY_REPORT.md`.

## 23. Documentation

`PROJECT_CURRENT_STATE.md`, `README.md`, `PROJECT_HANDOFF.md` (historical), this document, `FINAL_SYSTEM_TEST_MATRIX.md`, plus three new documents this session: `API_INVENTORY.md`, `RBAC_SCOPE_MATRIX.md`, `QA_DATA_INTEGRITY_REPORT.md`. All current as of this commit.

## 24. Known Limitations

- No Admin UI design system.
- No print system.
- No QA web app (a QA **data** factory exists; a QA **frontend** does not).
- No true multi-process concurrency/load testing (environment constraint — no separate MySQL QA database provisionable, SQLite is single-writer).
- No performance profiling under real load.
- No browser-driven E2E test.
- Production has near-zero real data — every "verified against production" claim should be read against that context. The QA dataset (776 records, 200 bookings) is now the stronger evidence source for anything financial/relational.
- Security review is bounded, not exhaustive or certified — though it did find and fix one real HIGH-severity live gap this session (`Franchises\Manage`).
- API test coverage is 4/24 routes at the HTTP level (up from 0), not exhaustive.

## 25. Open Business Decisions

Unchanged — plan pricing/quotas, commission override commercial terms, Worker compensation model, Business Account KYC requirement, Coupon system's commercial fate, and two new ones from this session: whether a duplicate pending payment-order/subscription should be blocked, replaced, or allowed (`API_INVENTORY.md`); what `provider_en_route`/`disputed` should actually mean given no code path produces them today (`QA_DATA_INTEGRITY_REPORT.md`). None invented or resolved.

## 26. Future Vertical Readiness

Unchanged from `PROJECT_CURRENT_STATE.md` §23 — Parcel remains the next planned vertical, not implemented, and nothing done this session narrows or widens that readiness.

## 27. Remaining Risks

1. **Admin UI inconsistency** — functional but not systematized; a real usability/consistency risk at scale, not a data-integrity one.
2. **API test coverage is partial (4/24 routes)** — most remaining routes' underlying logic is tested, but a route-level regression wouldn't be caught.
3. **No load-tested concurrency** — proven correct via lock-outcome tests (Action-layer and now HTTP-layer), not under genuine multi-process load; environment cannot currently provide a database that would make that test meaningful.
4. **Print system entirely absent** — if the business needs invoices/receipts/statements before mobile launch, this is unstarted work, not a small addition.
5. **`provider_en_route`/`disputed` have no implemented code path** — a real architectural gap surfaced by the QA data factory, not previously documented anywhere.
5. **Near-zero production data** — every piece of "verified on production" evidence in this and prior sessions carries this caveat.

## 28. Final Recommendation

**NOT READY for the next stage (Customer/Partner/Rider mobile apps + public website) without qualification** — unchanged verdict across both work programs this session, and it should stay unchanged: the gates that are genuinely unstarted (Admin UI design system, printing, QA web app, browser E2E, load-tested concurrency/performance) are exactly that — unstarted, not rough-but-present. Inflating this verdict because a second, larger pass of real work landed would be exactly the "manufactured success" this program's own rules forbid.

What DID move meaningfully in the second pass: a real, verified, disposable QA data factory now exists and produces the exact target dataset the sprint asked for (776 records, 200 bookings across every FSM-reachable status, financial reconciliation proven at scale — 80/80 commissions, 19/19 wallets, 0 mismatches); the API surface is now inventoried and has its first real HTTP-level tests; RBAC scope resolution is now formally documented; and — the most consequential outcome — that documentation work directly surfaced and fixed a real, live, HIGH-severity authorization gap (`Franchises\Manage`) that had shipped unnoticed. That last part is the whole point of this kind of program: not to produce reports, but to find what a report-writing pass actually catches.

Recommend the same path as before, now on firmer ground: treat backend/RBAC/financial/data-integrity hardening as complete enough to begin **API-first mobile app development in parallel** with the remaining Admin UI/printing/QA-app/browser-E2E work — those are genuinely independent tracks, and gating mobile development behind all of them finishing serially would waste the backend readiness that does now exist.
