# Exact Next Task

**Current HEAD:** `a2c443a` (nothing committed since)

## What was completed this session (continued across two work programs)

1. Full read-only forensic audit of everything after checkpoint `b95b58d` (git state, auth/OTP/QR code verified line-by-line against its own docs, admin panel + API inventory, database/domain table inventory, testing/printing/parity audit) — no false claims found; two new gaps surfaced (`Providers\Show::approve()` missing a permission check; `Auth\Login::submit()` hard-rejecting non-`super_admin` roles).
2. Finished the interrupted booking-OTP-delivery task: added `BookingOtpNotification::toMail()` (a real bug — the notification had no `toMail()`, and the *default* `notifications.channels` Setting is `'mail'`, so delivery was silently failing under default settings) and wrote `tests/Feature/Booking/BookingOtpDeliveryTest.php` (6 tests).
3. **(Second program, OFFLINE-SAFE only, no PHP runtime available)** Closed both Group A gaps named above:
   - Fixed `Providers\Show::approve()`/`reject()` — had **zero** authorization check (any admin-panel user, any role/scope, could approve/reject KYC). Extracted `canReview()` mirroring `Workers\Show::canReview()` exactly (that screen was built later and got the check; this one was left behind). Added `tests/Feature/Rbac/ProvidersReviewAuthorizationTest.php` (6 tests). A quick static sweep of all 20 Livewire admin components (mutator-method vs. permission-check heuristic, then manual read of every flagged file) found no other instance of this pattern — this was the one real outlier.
   - Fixed `Auth\Login::submit()` — confirmed via user decision this session that scoped admins (Country/City/Zone Admin, Franchise Owner, Operator, Support) *should* be able to log in. Root cause: `EnsureHasAdminAccess` (the middleware on every real `/admin` route) was already updated to admit "`super_admin` OR holds ≥1 `role_assignments` row" — its own docblock says so explicitly — but `Login::submit()`'s inline check was never updated to match, so scoped admins could authenticate correctly and still never reach the panel they were provisioned for. Fixed by mirroring `EnsureHasAdminAccess`'s exact predicate. Added `tests/Feature/Auth/AdminLoginAccessTest.php` (4 tests: super_admin unchanged, no-role-assignment user still rejected, scoped-admin-with-role-assignment now succeeds, wrong-password still rejected first).

## What was tested

**Not yet execution-verified — still true for all of the above, including this session's two new fixes.** No PHP runtime exists in this sandbox (no `php` on PATH, `vendor/` has no `laravel`/`illuminate` packages installed — see `CURRENT_MASTER_CHECKPOINT.md` §4). All four fixes (OTP `toMail()`, `Providers\Show`, `Auth\Login`) and their three test files (16 tests total) are statically reasoned and cross-checked against the codebase's own established patterns (`Workers\Show::canReview()`, `EnsureHasAdminAccess`, `BookingStatusNotification::toMail()`), not execution-confirmed. You are running the tests and will report back.

## What remains (Phases 2–30 of the completion mission, sequenced)

Nothing below has been started. Suggested order, grouped by what depends on what:

**Group A — close out the auth foundation properly**
1. Confirm `BookingOtpDeliveryTest`, `ProvidersReviewAuthorizationTest`, and `AdminLoginAccessTest` all pass, and the full suite has zero regressions (blocking everything else — see above), then commit all of it as one coherent close-out of the auth foundation's open items.
2. ~~Fix the two gaps found this session~~ — **done, pending execution verification (see above):** `Providers\Show::approve()`/`reject()` permission check added; `Auth\Login`'s role restriction fixed per your explicit decision this session (scoped admins should be able to log in) — mirrors `EnsureHasAdminAccess`'s existing predicate exactly, no new business rule invented.
3. Configure a real SMS/push provider before the login-OTP flow is usable outside a dev log (business decision: which provider — Twilio/MSG91/Fast2SMS for SMS, FCM for push).

**Group B — universal proof/verification + chat (Phases 4, 11)**
4. Design `CHAT_ARCHITECTURE.md` properly before writing code — the privacy boundaries (Customer must never see Partner↔Worker internal messages; wrong-actor denial on every pair) are a real authorization surface, not a CRUD screen; get this reviewed before it's built, given it's new schema + new RBAC surface.
5. Universal Proof/Verification engine — reuse the existing OTP/QR primitives rather than building a third mechanism; scope to Service start/completion only (Parcel/Taxi/Food stay out per the mission's own Phase 11 boundary).

**Group C — the financial/incentive engines (Phases 5, 6, 8, 9) — highest risk, lowest urgency**
6. Universal Referral/Recruitment engine with the configurable 30-day + 2–3-transaction qualification rule and anti-fraud — this is real money logic; build it as its own reviewable slice with its own test suite before touching Performance Campaigns.
7. Performance/Growth Incentive engine (Franchise/Partner/Rider/Customer campaigns) — depends on nothing in #6, can be built in parallel by a separate effort if needed.
8. Tip Engine + Extra Compensation Engine — both flow through the existing wallet/ledger, never touch balances directly.

**Group D — merchandising + operational UX (Phases 7, 10, 12, 13, 14)**
9. Badge/NEW-item engine (config-driven, admin-controlled, auto-expiry) — low financial risk, safe to parallelize with Group C.
10. Smart Job Rescue (risk-level dispatch visibility) — read-mostly, no financial writes, relatively low risk.
11. Service "no work found" outcome — needs a real business decision first (visit-charge policy), flag as **OPEN BUSINESS DECISION** until then.
12. Worker/skill matching test coverage — add missing tests to the *existing* dispatch logic, do not rewrite it.
13. Technician tracking/ETA — audit first; if no real GPS backend exists, document that honestly rather than build a fake tracker.

**Group E — platform completion (Phases 16–19, 27)**
14. Admin UI design system (largest single remaining scope per every prior audit — 24+ screens individually styled).
15. Printing architecture (does not exist at all today — no PDF library, no print views, for any document type).
16. HTTP-level tests for the 10 untested API routes found this session (Wallet, Loyalty, Plans, all Subscription self-service routes, Payment create-order/confirm, Provider Discovery).
17. Security pass on whatever new surface Groups B–D actually add (chat privacy, referral fraud, campaign reward abuse) — do this per-feature as it's built, not as one giant audit at the end.

**Group F — parity + QA (Phases 15, 20–25)**
18. `MASTER_FEATURE_PARITY_MATRIX.md` — genuinely blocked until real Glover/6ammart reference material is provided; the one existing attempt (`GLOVER_VS_1CALLFIX_AUTH_AUDIT.md`) states plainly that no such material was ever accessible. Cannot be honestly completed without it.
19. Expand `qa:seed`/`qa:clean` to cover whatever of Groups B–D actually gets built (referrals, campaigns, chat, tips) — do this alongside each feature, not as a separate pass.

## Exact next command/task

1. In an environment with PHP 8.3 + the real vendor install, run:
   - `php artisan test --filter=BookingOtpDeliveryTest`
   - `php artisan test --filter=ProvidersReviewAuthorizationTest`
   - `php artisan test --filter=AdminLoginAccessTest`
   - `php artisan test` for the full suite (expect 153 + 6 + 4 = 163 passing, 0 failures, 0 regressions)
   and report the output.
2. Once confirmed green: commit `BookingOtpNotification::toMail()` + `Providers\Show`'s `canReview()` fix + `Auth\Login`'s admin-access fix + all three new test files as one coherent commit (or split by concern if you prefer smaller commits — all three are independent, non-overlapping fixes), closing out every open item this session's forensic audit found.
3. After that, Group A is fully closed except #3 (real SMS/push provider — a business decision, not engineering). Pick that, or explicitly approve starting Group B/C — each of those is a real, separately-scoped engineering task that deserves its own session rather than being bundled into a single autonomous run.
