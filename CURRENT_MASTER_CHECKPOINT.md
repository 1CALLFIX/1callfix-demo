# 1CallFix — Current Master Checkpoint

**This document supersedes the previous checkpoint.** It reflects the repository as of this session. Where it conflicts with `PROJECT_CURRENT_STATE.md`, this document is more current for the specific items it covers; `PROJECT_CURRENT_STATE.md` remains authoritative for everything else until it is next synced.

**2026-08-14, FINAL EOD MISSION addendum — HEAD `3430197`.** Continuing from `6b7c36e` (306/306): `d1e7995` Performance/Growth Campaign Engine (34 tests) and `ac816a8`+`3430197` KYC completeness/video/withdrawal-restriction/support-request engine (44 tests). Full suite now **384/384, 883 assertions**. See `EXACT_NEXT_TASK.md`'s top section for the complete rundown. Everything below this point is the PRIOR checkpoint, kept for history.

Labels: **IMPLEMENTED** shipped and wired · **VERIFIED** confirmed by an automated test this session · **PARTIAL** exists but incomplete · **MISSING** does not exist · **BUSINESS DECISION** needs a human call, not an engineering one · **UNREACHABLE** code that would be dead given current guards elsewhere.

---

## 1. Git state

- **HEAD:** `c800ef8` — five new commits since `6e4c8e7` (the last checkpoint), all local to `main`, none pushed or deployed.
- **Production:** unchanged at `ba0635a` throughout.
- **Full suite progression, verified before every commit:** 226/226 → 251/251 → 293/293 → 306/306, 0 failures/errors/warnings at each checkpoint.

## 2. This session's five commits (continuing the "full-day autonomous mission")

**`d5c56c3` — `KNOWN_RISKS_AND_DECISIONS.md`** [IMPLEMENTED] — persistent, cross-session register of every unresolved business-decision blocker found by direct repository inspection. 12 items at last count, each with issue/current behavior/risk/why unresolved/decision required/safe default/affected modules/blocked status.

**`579d0d1` — Universal Badge Engine** [IMPLEMENTED] [VERIFIED, 25 tests] — `badges` (definition: label/styling/priority/mode/rule config) + `badge_assignments` (polymorphic entity, Plan-shaped scope). NEW is the one badge with a real automatic rule (`recently_created`, admin-configurable `within_days`), evaluated live with no persisted row and no cron dependency — "automatic disappearance" falls out of the rule check itself. POPULAR/TRENDING/FEATURED/BEST_VALUE/LIMITED/FLASH_SALE ship manual — no existing popularity/trending statistics engine exists to honestly automate them (confirmed: `RankingEngine` is provider-dispatch ranking, a different domain). `/admin/badges`, `badges.view`/`badges.manage` permissions, scope-checked mutations.

**`7aad4cc` — Flash Sale Engine** [IMPLEMENTED] [VERIFIED, 42 tests — exceeds the mission's 25-scenario requirement] — `flash_sales` + `flash_sale_targets` + `flash_sale_redemptions`. Full lifecycle with an explicit transition guard map; server-time-authoritative activeness (`FlashSale::isCurrentlyActive()` re-derived from `starts_at`/`ends_at`+`status` on every read, verified via time travel in tests, never trusting a stale status column). Integrates into the existing pricing cascade (base/discount price → `FranchiseServicePricing` override → flash sale) as one more layer, not a parallel system. Concurrency-safe redemption (`DB::transaction()`+`lockForUpdate()` on the sale row, same convention as `AcceptBookingAction`'s own offer-race guard). Duplicate-target and overlapping-active-sale prevention. `/admin/flash-sales`, `flash_sales.view`/`flash_sales.manage`, scope-checked mutations. Default-to-no-stacking with coupons/Plan Engine (risk register item 12).

**`c800ef8` — Referral engine hardening** [IMPLEMENTED] [VERIFIED, 14 new tests + 7 pre-existing re-confirmed] — Two real forensic findings shaped this slice's actual scope (see `EXACT_NEXT_TASK.md`): cross-actor qualification and cancellation-based clawback are both genuinely out of reach today (one a pending business decision, one dead code given existing guards) — neither was built or invented. What shipped instead: opt-in pending-referral expiry (`referral.pending_expiry_days` Setting, default off) with a new `referrals:expire-due` housekeeping command that can never race a legitimate reward; admin-driven manual fraud flag (`ReferralService::flagAsFraud()`) with wallet clawback via the existing `WalletService::debit()` (gracefully handling an already-spent balance, never crashing, never going negative), extending the *existing* `Loyalty\Index` Referrals tab with a new `loyalty.manage` permission rather than a new screen.

## 3. Full backend/admin capability snapshot (this session's additions layered onto the prior checkpoint's own findings — see `PROJECT_CURRENT_STATE.md` for everything not touched this session)

| Capability | Status |
|---|---|
| Row-level admin screen scoping (15 screens) | [IMPLEMENTED] [VERIFIED] — prior session |
| Operations/Troubleshoot | [IMPLEMENTED] [VERIFIED] — prior session |
| Payment Gateway abstraction + admin config/visibility | [IMPLEMENTED] [VERIFIED] — prior session |
| Universal Badge Engine | [IMPLEMENTED] [VERIFIED] — this session |
| Flash Sale Engine | [IMPLEMENTED] [VERIFIED] — this session |
| Referral engine (Customer↔Customer only) + manual fraud review + opt-in expiry | [IMPLEMENTED] [VERIFIED] — this session hardened it |
| Referral engine (cross-actor) | [BUSINESS DECISION] — qualification semantics undefined |
| Performance/Growth Campaign engine | [MISSING] — next in mission priority order |
| Tips/Compensation | [MISSING] |
| Universal Chat | [MISSING] — model exists, dormant |
| Printing/Document Engine | [MISSING] |

## 4. Do not confuse these

- All five commits above are **COMMITTED** to local `main` and **TEST-VERIFIED** (full suite run before each, not just a filtered subset).
- Nothing this session is **PUSHED**, **DEPLOYED**, or **PRODUCTION VERIFIED** — production remains `ba0635a`, untouched.
- Phases 4–21 of the full mission brief are **NOT STARTED, NOT CLAIMED COMPLETE** — see `EXACT_NEXT_TASK.md` for the exact remaining list and recommended next action.
