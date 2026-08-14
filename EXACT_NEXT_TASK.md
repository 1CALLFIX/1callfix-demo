# Exact Next Task

**Current HEAD:** `3430197` — continuing the FINAL EOD AUTONOMOUS COMPLETION MISSION from baseline `6b7c36e` (306/306). Two large milestones landed this segment:

1. **`d1e7995`** — Performance/Growth Campaign Engine (mission Phase 1). Configurable audience/scope/metric/qualification/reward architecture for Franchise/Partner/Worker/Customer incentive campaigns, reusing Wallet/Loyalty/Badge as the only payout rails, approve-then-disburse separation of duties, 34 tests. See its own commit message for full detail.
2. **`ac816a8`** + **`3430197`** (docs) — Partner/Worker KYC completeness, verification video, 30-day deadline, withdrawal restriction, franchise support-request workflow (mission Phases 2/3/4, built as one engine). Document security overhaul (closed a real raw-`<a href>` public-link gap), configurable required-document list, video requirement for Partner KYC, live-derived (never-boolean) withdrawal eligibility enforced at `PayoutService::request()`, franchise-raises/central-admin-decides support requests creating auditable time-bound exceptions. 44 tests. Two new risk-register items (13, 14).

Full suite: **384/384 passing, 883 assertions, 0 failures/errors/warnings.**

--- superseded below: prior checkpoint content retained for history ---

**Previous HEAD:** `c800ef8` (five new commits since the last checkpoint sync, all on `main`, none pushed/deployed to production — production remains `ba0635a`, untouched)

## What was completed in the "FULL-DAY AUTONOMOUS BACKEND + ADMIN COMPLETION MISSION" session (2026-08-14, continuation)

Genuine continuation from `6e4c8e7` (Payment Gateway abstraction). Five independently verified, independently committed milestones:

1. **`d5c56c3`** — Established `KNOWN_RISKS_AND_DECISIONS.md`, a persistent cross-session register of every unresolved business-decision blocker found by direct inspection so far.
2. **`579d0d1`** — Universal Badge Engine (mission Phase 1). Seeded the required example set (NEW/POPULAR/TRENDING/FEATURED/BEST VALUE/LIMITED/FLASH SALE). NEW has a real, non-invented automatic rule (recently_created, admin-configurable window); the rest ship manual since no popularity/trending statistics engine exists to honestly drive an automatic rule for them. New `/admin/badges` screen.
3. **`7aad4cc`** — Flash Sale Engine (mission Phase 2). Full lifecycle (draft→scheduled→live→paused→completed/cancelled) with server-time-authoritative activeness (never trusts a stale status column), integrated into the existing pricing cascade as one more layer, concurrency-safe redemption (row-locked), duplicate-target prevention. New `/admin/flash-sales` screen. 42 tests (mission asked for 25+).
4. **`c800ef8`** — Referral engine hardening (mission Phase 3, scoped down after real investigation). Opt-in pending-referral expiry + admin-driven manual fraud-flag with wallet clawback, extending the *existing* Loyalty screen. Did **not** extend cross-actor referral support or build automatic fraud detection — both are genuine pending business decisions (see risk register items 2 and 3), not engineering gaps.

**Test suite progression this session:** 226/226 → 251/251 → 293/293 → 306/306 passed. Full suite run and green before every single commit. 0 failures, 0 errors, 0 warnings at every checkpoint.

## Two real forensic findings that changed scope (documented, not silently worked around)

- **Referral reward clawback on booking cancellation would be dead code today.** A referral only qualifies off a `completed` booking; `AdminCancelBookingAction` explicitly refuses to cancel a `completed` booking. Same root cause already logged for commission clawback (risk register item 10, now cross-referenced). Did not build unreachable logic for either.
- **Cross-actor referral qualification is genuinely undefined**, not merely unbuilt — "what counts as a qualifying transaction for a referred Partner/Worker" has no existing analog to reuse (a customer's qualification is their first completed *booking*; a provider's would need to be something structurally different, e.g. first *accepted job*, which is a real product decision).

## What remains (honest, per the mission's own 21-phase priority order)

**Not started this session** (all genuinely large, independent programs):
- Phase 4 — Performance/Growth Campaign engine (the existing `CampaignService` is a notification-broadcast engine, unrelated; confirmed again this session, unchanged from the prior checkpoint's own finding).
- Phase 5 — Tips + waiting/rain/overtime/peak/night compensation (no model exists anywhere).
- Phase 6 — Universal Chat (`ChatMessage` model exists, zero controller/service/authorization/route — still dormant).
- Phase 7 — Printing/Document Engine (confirmed absent again).
- Phase 8 — Notification/Communication Center admin audit (provider status, templates, delivery logs beyond what Operations already surfaces).
- Phase 9 — Further Payment completion (`payment_methods`/`payment_accounts` admin UI — see risk register item 11).
- Phase 10–11 — Operations Control Center / System Health expansion beyond the existing `/admin/operations` screen (backup, import/export, CRON jobs visibility, clear-data controls).
- Phase 12 — Admin menu/settings completeness audit against the full reference checklist.
- Phase 13 — CMS/content audit (Terms/Privacy/Refund/Cancellation policy pages, SEO metadata).
- Phase 14 — Glover/6amMart parity audit.
- Phase 15 — QA data/seeder expansion for the new engines (badges, flash sales, referral fraud states).
- Phase 16 — Financial reconciliation audit incorporating the new flash sale / referral clawback paths.
- Phase 17 — API/security/E2E hardening sweep (IDOR testing systematically, beyond this session's own row-level-scope work).
- Phase 18–19 — Multi-country/multi-city/multi-zone + international-readiness audit.
- Phase 20 — Performance/scale audit (N+1, indexes, caching).
- Phase 21 — Final admin capability matrix.

## Exact next action (current, supersedes the paragraph above)

Continue in the new mission's priority order: **Phase 5 — Tips + waiting/rain/overtime/peak/night compensation.** Audit for any existing compensation model (none found in earlier forensic passes — confirm again before building), then build the configurable rule/ledger architecture (reusing WalletService, never a direct balance edit) WITHOUT inventing actual rates — those are logged as pending business decisions. Remaining phases after that, in order: 6 Universal Chat, 7 Printing/Document Engine, 8 Notification Center audit, 9 Payment Admin completion, 10 Operations/Troubleshoot expansion, 11 Admin Menu/Settings completeness audit, 12 CMS audit, 13 Glover/6amMart parity audit, 14 QA/realistic data, 15 Financial reconciliation, 16 API/IDOR/E2E security audit, 17 Multi-country/international readiness, 18 Performance/scale audit, 19 Final capability matrix, 20 Final release-readiness audit.
