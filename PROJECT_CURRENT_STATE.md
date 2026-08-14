# 1CallFix — Project Current State

**2026-08-14 addendum — see `CURRENT_MASTER_CHECKPOINT.md` / `EXACT_NEXT_TASK.md` for the full writeup.** Three verified commits since `a2c443a` (now at `53d6203`): (1) fixed the real cause of a booking-OTP-delivery test failure — `AcceptBookingAction`'s status notification wasn't isolated the same way its OTP notifications were; (2) closed a systemic gap where 12 seeded `.view` permissions across the admin panel (commissions, wallet ledger, loyalty/referrals, customers, dashboard, bookings, providers, workers, subscriptions, plans, notification center) were never actually enforced — any admin-panel actor could view all of it regardless of role/scope, now fixed on all 15 affected screens; (3) built the Operations/Troubleshoot admin screen (failed jobs, notification delivery failures, system health) that was completely absent before. Full suite: 173/173 passing. Nothing pushed or deployed — production unchanged at `ba0635a`.

**This is the authoritative current-state document.** Where it conflicts with `PROJECT_HANDOFF.md` or anything else, this document wins — `PROJECT_HANDOFF.md` predates most of what's described below and is marked HISTORICAL at the bottom of this file. Verified against the actual repository and, where noted, direct read-only inspection of the production database — not against memory or old planning docs.

**Baseline for everything below:** commit `f5bdd50` on `main`, 2026-08-13. (Originally written at `e2a169e` 2026-08-12, updated in place through three further work programs — see `PRODUCTION_READINESS_AUDIT.md`, `FINAL_SYSTEM_TEST_MATRIX.md`, `QA_DATA_INTEGRITY_REPORT.md`, `API_INVENTORY.md`, `RBAC_SCOPE_MATRIX.md`, and the seven new `AUTH_FORENSIC_DISCOVERY.md`/`AUTHENTICATION_ARCHITECTURE.md`/`OTP_ARCHITECTURE.md`/`QR_SCAN_ARCHITECTURE.md`/`NOTIFICATION_ARCHITECTURE.md`/`OTP_DELIVERY_MATRIX.md`/`GLOVER_VS_1CALLFIX_AUTH_AUDIT.md` documents for the fuller writeup of everything added since.)

Labels used throughout: **[IMPLEMENTED]** shipped and in the codebase · **[VERIFIED]** implemented AND confirmed by an automated test or direct inspection this session · **[PARTIAL]** exists but incomplete · **[DEFERRED]** deliberately not built yet · **[OPEN BUSINESS DECISION]** needs a human call · **[FUTURE]** planned, not started.

---

## 1. Project Vision

A franchise-ready, multi-vertical home-services super-app for 1CallFix Solutions Pvt Ltd. One codebase, multiple verticals toggleable per franchise (`franchise_modules`). **Service is the only live vertical.** Parcel is the next one planned; no other vertical (Taxi/Food/Grocery/Pharmacy/Commerce/Bookings) has any real implementation — only the module-toggle flag exists in schema.

## 2. Current Production Status

- Live at `api.1callfix.com`, Hostinger VPS (`srv1422426.hstgr.cloud`), CyberPanel + OpenLiteSpeed.
- **Production data volume as of this session (read-only check): 0 bookings, 0 commissions, 0 coupons, 2 users, 4 franchises, 3 zones.** This is a pre-launch/early-setup state, not a system carrying real customer transaction volume yet — worth knowing before treating any "production" statement as implying live business traffic.
- Deploy method: SCP or `git pull` directly against the production checkout; commits in this repository's history describe a pattern of testing changes live against production with disposable, cleaned-up fixtures, then reverting or deploying the code for real. No staging environment exists separately from production.

## 3. Tech Stack

- Laravel `^13.8` (confirmed installed: 13.24.0), PHP 8.3.30
- Livewire `^4.3` — the entire admin panel, no separate JS framework/build step, Tailwind via CDN
- MySQL in production; **automated tests run against SQLite `:memory:`** per `phpunit.xml` (see §19 — this only became actually runnable this session, see the Testing section)
- Sanctum `^4.0` for API token auth (mobile-app-facing)
- `maatwebsite/excel` for catalog CSV/XLSX import-export
- Queue: `database` driver (`QUEUE_CONNECTION=database` in `.env.example`), Supervisor-managed worker on the server. Redis installed but unused.
- Razorpay for payments (test mode)

## 4. Architecture — Actor Model

Three intended application experiences — **Customer**, **Partner**, **Rider** — per the approved architecture. Only the **Admin Panel** (Livewire) actually exists as a UI today; no mobile app exists in this repository (see §21). The API surface (`routes/api.php`) is the beginning of the mobile-facing contract, not a finished one.

**Provider/Partner** — accountable business, accepts jobs, may self-perform or delegate. **Worker** — physically executes work, may belong to a Partner or be platform-direct, may hold multiple capabilities. These are deliberately separate concepts (`providers` table vs. `field_workers` table), not layered on top of each other. [IMPLEMENTED] [VERIFIED]

## 5. Booking FSM

`bookings.status` enum: `pending, searching_provider, assigned, provider_en_route, in_progress, on_hold, completed, cancelled, disputed`.

**Correction to an earlier finding in this same session:** an intermediate audit pass in this session claimed `on_hold` was NOT a real status value and only existed as side-columns. That was wrong — it was based on reading only the original `create_bookings_table` migration and missing a later `ALTER` (`2026_08_03_001000_add_hold_tracking_to_bookings_table`) that adds `on_hold` to the enum for real. **`on_hold` IS a genuine FSM status.** It coexists with `hold_category` / `hold_reason` / `hold_note` / `on_hold_since` side-columns that classify *why* a booking is on hold (`customer_side` routine vs. `provider_side` red-flag) — both mechanisms are real and used together, not one instead of the other. [VERIFIED] — confirmed by reading the actual migration DDL and by successfully running it against a live database this session.

Transitions are handled by dedicated Action classes (`AcceptBookingAction`, `StartBookingAction`, `CompleteBookingAction`, `PlaceBookingOnHoldAction`, `ResumeBookingAction`, `AdminCancelBookingAction`, `AdminReassignBookingAction`, `AssignBookingToWorkerAction`, `CreateBookingAction`), each wrapping its mutation in `DB::transaction()` + `Booking::lockForUpdate()`, and each writing a `booking_status_history` row. [IMPLEMENTED] [VERIFIED for the worker-delegation and RBAC-touching actions specifically, via this session's test suite]

## 6. Dispatch Architecture

`ServiceMatchingJob` — self-requeuing queued job, up to 5 nearest eligible providers offered at once, 25-second window, up to 6 rounds before falling to the admin's manual queue. `dispatch_attempts` is polymorphic (Phase B0.3), ready for future verticals to reuse. `DispatchService` provides the shared primitives (`eligibleQuery`, `hasSkill`, `withDistance`, `rankAndLimit`).

A real concurrency bug was found and fixed in an earlier session (`1e108ff`): the job's `pending -> searching_provider` transition read the booking without a row lock and wrote back without a transaction — the one exception to this codebase's otherwise-universal locking convention. Fixed to lock-then-reverify. **This fix had zero automated regression coverage before this session and still does** — it was verified only via a manual production reproduce-and-fix cycle. [IMPLEMENTED] [PARTIAL — fix shipped, automated regression test still outstanding, see §24]

## 7. Wallet Architecture

`WalletService` is the **only** writer of `wallets.balance` — confirmed by inspection (`app/Services/`), only one service exists, no `parallel_wallet`/`worker_wallet` files anywhere. Transaction-safe (row-locked), refuses to let a wallet go negative. [IMPLEMENTED] [VERIFIED — inspected this session, no second implementation found]

## 8. Commission Architecture

`CommissionService::applyForBooking()` is the sole financial-split authority — provider/franchise/platform shares computed from the franchise's own configured rates (with an optional Plan Engine commission-rate override), one `Commission` row per booking, provider's wallet credited via `WalletService`. Idempotent by design: an existing `Commission` row for a booking short-circuits before any insert.

**New this session:** a DB-level `UNIQUE` constraint on `commissions.booking_id` (migration `2026_08_12_001000`) now backstops that application-level check — verified there were zero existing rows and zero duplicates on production before adding it. **New this session:** a real automated test (`tests/Feature/Finance/CommissionIdempotencyTest.php`) confirms calling `applyForBooking()` twice returns the same row without double-crediting the wallet, confirms the split math against configured rates, and confirms the DB constraint itself rejects a direct duplicate insert. [IMPLEMENTED] [VERIFIED]

`CompleteBookingAction` calls `applyForBooking()` deliberately *outside* the booking's row lock (to avoid holding it longer than necessary) — safe in practice because the booking's own status-transition lock already prevents two completions of the same booking from both reaching the commission call in the first place.

## 9. Loyalty / Referral

`LoyaltyService` (earn/redeem) and `ReferralService` (create-from-signup, qualify-from-completed-booking) — real, single implementations, no second ledger. Not covered by this session's new automated tests (see §24 remaining work).

## 10. Plan Engine (Phase A) — PROTECTED, UNTOUCHED

`plans`, `plan_entitlements`, `subscriptions`, `entitlement_balances`, `usage_ledger`, `RenewalService`, `EntitlementService`, and the rest of `app/Services/Plans/*` were explicitly frozen for this session per the mission brief. **Not modified.** No automated smoke test added for it this session (remaining work, see §24).

## 11. Business Accounts

`business_accounts` / `business_locations` tables exist (Plan Engine era). Not audited or touched this session.

## 12. Admin Panel

Livewire 4, 24 module folders under `app/Livewire/`, ~30 registered admin routes (`routes/admin.php`). Real, working screens as of this session: Dashboard, Bookings (list + detail + admin booking creation + worker assignment), Providers, Workers (KYC review, capabilities, activation), Franchises, Franchise Pricing, Zones, Geography (Countries/Cities), Categories, Subcategories, Services, Customers, Roles & Permissions, Wallet Ledger, Loyalty & Referrals, Commissions, Payouts, Banners, CMS (Pages/FAQs), Notification Center, Plans, Subscriptions.

**UI design system: NOT started.** Only 7 one-off Blade components exist (`address-map`, `catalog-tabs`, `icon`, `import-panel`, `setting-override-badge`, `yes-no`, `zone-map`) — no shared button/card/table/badge/modal/status-pill primitives. Each screen is still styled independently. This is the single largest piece of unstarted work from the makeover brief (Priority 4/5 in this session's work order). [DEFERRED — not attempted this session, see §24]

## 13. RBAC

`AuthorizationService::can()` — scope-aware (`global/country/city/zone/module/franchise`), additive across multiple `role_assignments`, fail-safe. `User::hasPermission()` is the call-site entry point. Seven system roles seeded: `super_admin, country_admin, city_admin, zone_admin, franchise_owner, operator, support`.

**This session closed all 7 previously-open enforcement gaps** (commit `97c186d`): `zones.manage`, `services.manage`, `categories.manage` (also governs subcategories — the seeded permission label is literally "Manage categories & subcategories", confirmed the least-disruptive correct reading rather than inventing a new permission), `banners.manage`, `cms.manage`, and `bookings.create`/`createCustomer`/`addNewAddress`. Scope resolution for each was derived from the actual schema (Zones/Bookings: franchise→city→country cascade; Categories/Subcategories/Services/CMS: global-only, since those tables carry no franchise column; Banners: the banner's own `franchise_id`, which is a targeting axis as much as ownership). A new `AuthorizationService::canAnywhere()` handles the one genuinely different case — `createCustomer`, which runs before a zone exists to scope against.

A separate, more severe pre-existing gap (`Roles\Manage::assign()/revoke()` had **zero** authorization checks — any single-scope actor could grant themselves global `super_admin`) was found and fixed in an earlier session (`21a1fcd`). [IMPLEMENTED] [VERIFIED — 44 + 12 + 6 = a good chunk of the new automated test suite exercises exactly these paths, all passing]

## 14. Worker / Rider Architecture

`field_workers` / `field_worker_capabilities` / `partner_workers` / `field_worker_documents` (Phase B0.1) — a Worker is a first-class identity (1:1 with `users`), independent of `providers`, linkable to zero or more Partners via `partner_workers`, holding zero or more capabilities via `field_worker_capabilities`. Deliberately NOT built as separate Parcel-Rider/Taxi-Driver/Handyman entities — one unified foundation.

`AssignBookingToWorkerAction` (Phase B0.2) enforces every boundary the makeover brief lists: booking ownership, assignable-status window, worker active/inactive, active team-link required, capability match (including a `null`-scoped capability matching any Service category). **This session added 12 real tests covering every one of these boundaries — all passed on the first run**, confirming the implementation was already correct; this closes the "proven only by a manual test cycle" gap for this specific action. [IMPLEMENTED] [VERIFIED]

## 14.5. Authentication Foundation — Customer/Partner/Worker login, OTP, QR pairing (new)

**Full detail in `AUTHENTICATION_ARCHITECTURE.md`, `OTP_ARCHITECTURE.md`, `QR_SCAN_ARCHITECTURE.md`, `NOTIFICATION_ARCHITECTURE.md`, `OTP_DELIVERY_MATRIX.md`, `AUTH_FORENSIC_DISCOVERY.md`, `GLOVER_VS_1CALLFIX_AUTH_AUDIT.md`.** Before this session, `routes/api.php` required `auth:sanctum` on every route with nothing that ever issued the first token — Customer/Partner/Worker login was **NOT IMPLEMENTED** in any form.

**Now implemented and tested (26 tests, 99 assertions):**
- `POST /api/auth/otp/request` + `/otp/verify` — shared OTP login for all three actor types (`actor_type: customer|provider|field_worker`), one implementation, not three. Customer self-registers on first verified login; Provider/Worker must already have an approved profile (KYC-gating preserved, never bypassed by OTP login). Enumeration-safe (identical response whether or not a provider/worker account exists), rate-limited (`throttle:5,1`, verified empirically both outside and inside the test suite).
- `POST /api/auth/logout`, `POST /api/auth/device` (single-device push token registration — see the known limitation below).
- `POST /api/auth/qr/create` + `/qr/status` + `/qr/confirm` + `/qr/claim` + `/qr/revoke` — QR "login with the app" device pairing, brand new (zero QR code existed anywhere before this session). Two distinct opaque tokens per challenge (`qr_token` for the rendered image, `poll_token` for the initiating side only) specifically so a photo of the displayed QR can never be used to steal the resulting session — the single most important security decision in this design.

**The shared login OTP engine** hardens the previously-dormant, zero-consumer `otps` table (hashed code storage, attempt lockout, resend cooldown, full audit trail) — **the existing Service booking OTP (`bookings.start_otp`/`completion_otp`) is completely untouched**, a deliberate Option-C hybrid decision (`OTP_ARCHITECTURE.md`), not a redesign.

**Real, previously-undocumented gap found and NOT silently patched:** the Service booking start/completion OTP is generated and verified correctly but is **never actually delivered to the customer** by any channel — not SMS, not push, not any admin screen. Traced exhaustively this session, flagged in the remaining-work list below, not fixed (fixing it means adding a notification call inside `AcceptBookingAction`, which deserves more deliberate review than a same-session drive-by edit).

**Known limitation, stated plainly:** `users.fcm_token` is a single nullable column — no `devices` table, no real multi-device support. Registering a second device silently overwrites the first.

**Explicit pre-production requirement:** a real SMS/push provider must be configured (`AppServiceProvider::register()`'s two binding lines) before this login flow reaches real users — until then, `LogSmsAdapter` writes OTP codes to the server log (safe in this dev/QA environment, unsafe in production).

## 15. Current APIs

`routes/api.php`: 24 routes, fully inventoried (`API_INVENTORY.md`), plus 9 new authentication routes this session (not yet added to that inventory document — see remaining work). Sanctum-protected except the new `/auth/*` endpoints (unauthenticated by necessity — that's how a token is obtained) and the pre-existing Razorpay webhook (signature-verified instead). No Customer App business-logic endpoints beyond authentication were added; no future-vertical endpoints were added.

## 16. Current Database Architecture

119 migrations as of this session's start, 122 after this session's additions. Every operational table carries `franchise_id`; most carry `zone_id`.

**This session's hardening (all additive, all verified via full `migrate` + `migrate:rollback` + re-`migrate` round-trip on an isolated database, all read-only-checked against production first):**
- `commissions.booking_id` now has a `UNIQUE` constraint (0 existing rows/duplicates confirmed first).
- `bookings.coupon_id` now has a real `FOREIGN KEY` to `coupons` (`nullOnDelete`) — it was a bare unconstrained column before, unlike `coupon_usages.coupon_id` and `notification_campaigns.coupon_id`, which already referenced `coupons` properly. Completing an existing pattern, not inventing a new decision. (0 non-null values on production, so nothing was at risk.)
- `bookings.status` and `bookings.completed_at` are now indexed — based on grepping actual query usage across `app/` (Dashboard KPI queries, `DispatchService`'s busy-provider lookup, the admin Bookings list filter), not assumption. `payment_status`, `payment_method`, and `scheduled_at` were assessed and deliberately **not** indexed: they're written to but never appear in a `WHERE`/`whereIn`/`orderBy` anywhere in the current codebase.

**Also discovered and fixed this session:** three historical migrations used raw MySQL-only `ALTER TABLE ... MODIFY COLUMN` DDL (`2026_08_03_001000`, `2026_08_11_015000`, `2026_08_11_037000`) — this made the *entire* migration chain impossible to run from scratch against SQLite, which `phpunit.xml` configures for the automated test suite. This is almost certainly why "committed automated tests are effectively absent" in every prior audit of this project — nobody could get a migrated test database working at all. Replaced with Laravel's native `Blueprint::change()` (no `doctrine/dbal` needed since Laravel 9+), which compiles to the equivalent `ALTER` on MySQL and to SQLite's own table-rebuild strategy on SQLite. No production impact — all three migrations had already run for real; editing the file only changes what happens on a *fresh* environment.

One narrower, separate SQLite-only quirk was found (not fixed, out of scope): `2026_08_01_003500_add_owner_fk_to_franchises_table`'s `down()` fails on SQLite during a full `migrate:reset` stress test — rollback-only, doesn't block forward migration or `RefreshDatabase`-based tests.

## 17. Queue Architecture

`database` queue driver, Supervisor-managed persistent worker on the server. Not modified or audited beyond what's noted in §6 (the dispatch race fix) this session.

## 18. Current Deployment State

Production is at commit `ba0635a`, confirmed via direct SSH check at the end of this session (`git status --short` clean, `git log -1` = `ba0635a`) — unchanged from the start of this session. This session's commits (`97c186d` through `da7c5b4`) are pushed to `origin/main` on GitHub but **not deployed to production** — per the mission brief's production-safety rules, deployment remains a separate, controlled, human-triggered operation.

## 19. Testing — Before and After This Session

**Before this session:** two Laravel stub tests (`tests/Unit/ExampleTest.php`, `tests/Feature/ExampleTest.php`), and — critically — the full migration chain could not even run against the configured test database (see §16). Effectively zero real regression coverage existed, and the tooling to build any hadn't been verified to work at all.

**After this session (continued across four work programs across two days):** 147 real, executed, passing tests (407 assertions), covering:
- **New in the fourth program:** the login OTP + QR device-pairing authentication foundation (26 tests, 99 assertions) — Customer/Partner/Worker login, enumeration safety, lockout/expiry/resend, rate limiting (verified empirically, not assumed), full QR pairing lifecycle including replay protection and the two-token security design. Zero regression against the existing Service booking OTP flow.
- RBAC enforcement for all 7 newly-closed gaps + cross-scope denial cases + Super Admin bypass regression (44 tests)
- The `Roles::assign()/revoke()` privilege-escalation fix, previously uncovered (6 tests)
- **A live HIGH-severity gap found and fixed in the third program: `Franchises\Manage::update()/toggleStatus()/deleteFranchise()` had zero authorization check** (6 tests)
- Commission idempotency, including the new DB constraint (3 tests)
- Worker delegation authorization boundaries — every case the makeover brief names (12 tests)
- Booking FSM: Start/Complete/AdminCancel/AdminReassign transition boundaries, including duplicate-completion and extra-work pricing (16 tests)
- Dispatch race regression — direct test for `1e108ff`, previously zero coverage (4 tests)
- Plan Engine smoke suite — Phase A untouched, includes a direct regression test for the renewal-upgrade bug `4f92fdf` (6 tests)
- Loyalty (7 tests) and Referral (7 tests)
- **New in the third program:** real HTTP-level API tests (14 tests) — `DispatchApiTest`/`WorkerJobApiTest`, actual `postJson()`/`getJson()` calls through the full route/middleware/controller pipeline, not Action-layer calls dressed up as API tests. First HTTP-level coverage this codebase has ever had.
- **New in the third program:** a real, disposable QA data factory (`qa:seed`/`qa:clean`) producing the exact target dataset at scale (776 records, 200 bookings across every FSM-reachable status, 30 subscriptions) — see `QA_DATA_INTEGRITY_REPORT.md`. Financial reconciliation proven at that scale: 80/80 commissions match completed bookings exactly, 19/19 wallets reconcile exactly, 0 orphans.

All executed against an isolated SQLite database on the production server's own filesystem (never against production data, never against local MySQL), via a throwaway checkout cloned/pulled from GitHub — read-only against the live directory, never modified it. Stability confirmed across 5 consecutive full-suite runs after fixing a real flaky-fixture bug found along the way (countries.code collision — see the `8f1d0ae` commit). A second real bug was found and fixed via this testing: `RazorpayService`'s constructor crashed with an uncaught `TypeError` on any environment without Razorpay credentials configured (`.env.example` has none), which crashed `AdminCancelBookingAction` even for bookings that were never paid.

**Full detail, including the itemized test matrix and a production-readiness verdict, is in `FINAL_SYSTEM_TEST_MATRIX.md` and `PRODUCTION_READINESS_AUDIT.md`** — read those for the authoritative "what's tested, what's not" breakdown rather than relying on this summary alone. Still not covered: dedicated API-endpoint tests, browser-driven E2E, the full 27-point-per-screen Admin UI functional QA matrix.

## 20. Known Limitations

- Admin UI has no shared design system (§12).
- API layer unaudited (§15) — no dedicated endpoint tests exist.
- No print system, no QA web app, no browser-driven E2E test — all entirely unstarted, see `PRODUCTION_READINESS_AUDIT.md`.
- Production data volume is effectively zero — every "verified on production" claim in this and prior sessions' commit messages should be read as "verified against a system with no real customer load," not battle-tested at scale. Confirmed again via a 24-check read-only integrity sweep this session (zero issues found, but on near-empty tables — see `PRODUCTION_READINESS_AUDIT.md` §3).

## 21. Mobile App Boundary

No mobile application exists in this repository. Not fabricated, not scaffolded, this session or before.

## 22. Open Business Decisions

Carried forward, none resolved or invented this session:
- Final plan prices, quotas, overage rates, rollover caps
- Commission reduction/override commercial availability terms
- Worker compensation model
- Business Account KYC requirement
- Coupon system: infrastructure is being completed (this session's FK addition) but whether/when Coupons ship as a real customer-facing feature is still undecided
- `bookings.scheduled_at`/`payment_status`/`payment_method` indexing — revisit once a real filter on these actually ships (see §16)

## 23. Future Vertical Roadmap

Parcel is next, explicitly not implemented this session or before. `FieldWorker`, worker capabilities, `dispatch_attempts` (polymorphic), `WalletService`, Plan Engine, RBAC, and geography are all structured to be reusable by it without a foundation rebuild — nothing done this session narrowed that.

## 24. Remaining Work (priority order)

All P1 testing-gap items from the original list (Dispatch race, Booking FSM, Provider self-completion, Admin reassignment, Plan Engine smoke, Loyalty, Referral) are **done** — see `FINAL_SYSTEM_TEST_MATRIX.md`. The authentication foundation (login OTP, QR pairing) is now also **done** — see §14.5. What's left:

**P1 — Deliver the Service booking OTP to the customer** (real gap found this session — `AcceptBookingAction` generates it, nothing sends it to the customer by any channel; see `AUTH_FORENSIC_DISCOVERY.md`/`OTP_ARCHITECTURE.md`)

**P2 — Configure a real SMS/push provider before the new login flow reaches real users** (currently `LogSmsAdapter`/`LogPushAdapter` — safe for dev/QA, logs plaintext OTP codes, must be swapped before production use — see `NOTIFICATION_ARCHITECTURE.md`)

**P3 — Admin UI design system** (largest remaining scope — not started at all: no shared components, 24 screens each styled independently)

**P4 — API/error handling audit for the pre-existing 24 business-logic routes** (2 of 24 have HTTP-level tests; the new 9 auth routes are fully tested — see `API_INVENTORY.md`, not yet updated with the new routes)

**P5 — Printing system** (does not exist — no print views, no PDF generation, for any document type)

**P6 — QA web app** (does not exist — no standalone frontend connected to the real backend for role-based journey testing)

**P7 — Performance profiling under real load** (query-usage-based index justification was done; live profiling was not)

**P8 — Browser-driven E2E** (no Playwright/Chrome-automation-driven multi-actor journey has been run)

**P9 — Multi-device support** (`users.fcm_token` is a single column, no `devices` table — a real schema expansion, deliberately not attempted this session)

## 25. Exact Current Phase / Status

**RBAC hardening: COMPLETE and tested.** **DB hardening: COMPLETE and tested.** **Authentication foundation (login OTP + QR device pairing): COMPLETE and tested** — Customer/Partner/Worker login all share one implementation, zero regression against the existing Service booking OTP, two real pre-production requirements documented (deliver booking OTP to customer; configure a real SMS/push provider). **Automated regression foundation: SUBSTANTIAL, real and passing** — 147 tests covering RBAC, Booking FSM, dispatch race safety, worker delegation, financial idempotency, Plan Engine (Phase A untouched), Loyalty, Referral, and now authentication/OTP/QR; still not the exhaustive per-screen/per-API coverage a full production-readiness program calls for. **Admin UI makeover: NOT STARTED** — largest remaining scope. **Printing system: NOT STARTED.** **QA web app: NOT STARTED.** **Documentation: this document + `PRODUCTION_READINESS_AUDIT.md` + `FINAL_SYSTEM_TEST_MATRIX.md` + the six new authentication documents.** **Official verdict: NOT READY for the next stage (mobile apps + website) without qualification** — but the authentication foundation specifically, the thing this session's mission was scoped to, is genuinely ready: real, tested, documented, with its two remaining pre-production requirements named precisely rather than hidden.

---

## HISTORICAL DOCUMENTS

- **`PROJECT_HANDOFF.md`** — superseded by this document. It predates RBAC, the Plan Engine, Worker/Rider architecture, and most of the current Admin Panel screens; it incorrectly describes several shipped features ("Banners management" etc.) as not yet built. Kept for its infrastructure/deployment notes (server paths, backup schedule, known one-time infra bugs), which are still accurate.
- **`PHASE_B0_1_WORKER_FOUNDATION.md`**, **`PHASE_B0_2_SERVICE_WORKER_DELEGATION.md`**, **`PHASE_B0_3_DISPATCH_POLYMORPHISM.md`** — accurate as phase-completion records of the work they describe; not updated to reflect this session's changes, which build on top of them without altering their content.
