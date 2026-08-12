# 1CallFix — Project Current State

**This is the authoritative current-state document.** Where it conflicts with `PROJECT_HANDOFF.md` or anything else, this document wins — `PROJECT_HANDOFF.md` predates most of what's described below and is marked HISTORICAL at the bottom of this file. Verified against the actual repository and, where noted, direct read-only inspection of the production database — not against memory or old planning docs.

**Baseline for everything below:** commit `e2a169e` on `main`, 2026-08-12.

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

## 15. Current APIs

`routes/api.php` exists (Sanctum-protected, mobile-facing). **Not audited this session** — Priority 5 of the work order (API/error/performance) was not reached. No Customer App endpoints beyond what already exists were added; no future-vertical endpoints were added.

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

Production is at commit `ba0635a` as of the start of this session (unverified whether it has since been updated — this session did not deploy anything to it; see §25). This session's 6 commits (`97c186d` through `e2a169e`) are pushed to `origin/main` on GitHub but **not deployed to production** — per the mission brief's production-safety rules, deployment remains a separate, controlled, human-triggered operation.

## 19. Testing — Before and After This Session

**Before this session:** two Laravel stub tests (`tests/Unit/ExampleTest.php`, `tests/Feature/ExampleTest.php`), and — critically — the full migration chain could not even run against the configured test database (see §16). Effectively zero real regression coverage existed, and the tooling to build any hadn't been verified to work at all.

**After this session:** 61 real, executed, passing tests (160 assertions), covering:
- RBAC enforcement for all 7 newly-closed gaps + cross-scope denial cases + Super Admin bypass regression (44 tests)
- The `Roles::assign()/revoke()` privilege-escalation fix, previously uncovered (6 tests)
- Commission idempotency, including the new DB constraint (3 tests)
- Worker delegation authorization boundaries — every case the makeover brief names (12 tests)

All executed against an isolated SQLite `:memory:` database (never against production or any real MySQL data), via a throwaway checkout cloned locally from the production `.git` on the same server — read-only against the live directory, never modified it. **Not yet covered** (see §24): Booking FSM transition tests, Provider self-completion, Admin reassignment, Wallet ledger discipline beyond commission crediting, Plan Engine smoke tests, Loyalty/Referral, Dispatch race regression (the fix exists, the test doesn't yet), Admin Panel authorization beyond what's listed above.

## 20. Known Limitations

- Admin UI has no shared design system (§12).
- API layer unaudited (§15).
- Production data volume is effectively zero — every "verified on production" claim in this and prior sessions' commit messages should be read as "verified against a system with no real customer load," not battle-tested at scale.
- The dispatch-race fix and several other financially/operationally significant fixes from prior sessions have no automated regression coverage yet.

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

**P1 — Testing gaps with real financial/safety exposure:**
- Dispatch race regression test (the fix exists, `1e108ff`, zero automated coverage)
- Booking FSM transition tests (locked/authorized/auditable, per Part 8 of the brief)
- Provider self-completion + Admin reassignment tests

**P2 — Admin UI design system** (Priority 4/5 of the work order — not started, largest remaining scope)

**P3 — API/error handling/performance audit** (Priority 5/6 — not started)

**P4 — Remaining test areas:** Plan Engine smoke tests, Loyalty/Referral, wider Wallet ledger discipline

**P5 — README replacement** (this session wrote this document; `README.md` is still the stock Laravel boilerplate as of this commit)

## 25. Exact Current Phase / Status

**RBAC hardening (Slice 2): COMPLETE and tested.** **DB hardening (Slice 3): COMPLETE and tested.** **Automated regression foundation (Slice 4): STARTED, real and passing, not exhaustive** — 61 tests is a genuine foundation, not the full coverage the brief's test matrix calls for. **Admin UI makeover (Slices 7-8): NOT STARTED.** **API/error/performance hardening (Slice 9): NOT STARTED.** **Documentation reset (Slice 10): this document.** **Full integration verification (Slice 11): NOT ATTEMPTED** — no realistic multi-actor end-to-end run (Part 23 of the brief) was performed this session.

---

## HISTORICAL DOCUMENTS

- **`PROJECT_HANDOFF.md`** — superseded by this document. It predates RBAC, the Plan Engine, Worker/Rider architecture, and most of the current Admin Panel screens; it incorrectly describes several shipped features ("Banners management" etc.) as not yet built. Kept for its infrastructure/deployment notes (server paths, backup schedule, known one-time infra bugs), which are still accurate.
- **`PHASE_B0_1_WORKER_FOUNDATION.md`**, **`PHASE_B0_2_SERVICE_WORKER_DELEGATION.md`**, **`PHASE_B0_3_DISPATCH_POLYMORPHISM.md`** — accurate as phase-completion records of the work they describe; not updated to reflect this session's changes, which build on top of them without altering their content.
