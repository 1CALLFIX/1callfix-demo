# Phase 21 — Release Candidate Hardening Backlog

**Status: PLANNING DOCUMENT ONLY. No code was changed, no production system was touched, and no business questions were asked to produce this document.** Everything below is derived from direct inspection of the actual repository (migrations, models, controllers, Livewire components, config files, tests) reconciled against the six existing audit documents. Where a document's claim couldn't be independently confirmed in code, that is stated explicitly rather than assumed.

**Source documents reconciled:** `FINAL_RELEASE_READINESS_AUDIT.md`, `KNOWN_RISKS_AND_DECISIONS.md` (28 items), `CURRENT_MASTER_CHECKPOINT.md`, `FINAL_ADMIN_CAPABILITY_MATRIX.md`, `PRODUCTION_READINESS_AUDIT.md` (2026-08-12 baseline, formally superseded), plus the Phase 1–20 narrative entries inside `CURRENT_MASTER_CHECKPOINT.md §2` for anything an open item references.

**As of this document:** 620/620 tests passing, 1480 assertions, working tree clean, HEAD at commit `ed26e48` on `main`. Nothing this document proposes has been started.

**Phase 21 progress (updated in place, matching this document's own stated discipline — see §"This document is now the source of truth" at the end):** DOC-1, TECH-1, TECH-2, TECH-3, and TECH-4 are now COMPLETE — see their own entries below for scope and commit hashes. Current baseline: 687/687 tests passing, 1578 assertions, HEAD at commit `e431667` on `main`, none pushed. All other items in this document remain open exactly as originally assessed.

---

## 0. Contradictions found between audit documents

Found by direct reconciliation, not assumed. These are documentation-accuracy problems in their own right — real enough to bias a future session's judgment if left uncorrected — not new product findings.

| # | Contradiction | Evidence | Severity |
|---|---|---|---|
| C1 | **`CURRENT_MASTER_CHECKPOINT.md` is stale on Risk #25.** Lines 85 and 97 of its capability table still read `Production APP_DEBUG value \| [CONFIRMED ACTIVE — true]`, and commit-log entry #19 (line 35) still describes only the detection, not the fix. `KNOWN_RISKS_AND_DECISIONS.md` item 25 and `FINAL_RELEASE_READINESS_AUDIT.md` both correctly show **RESOLVED** as of commit `ed26e48`. `CURRENT_MASTER_CHECKPOINT.md` was never updated after that commit. | Direct read of all three files, same session. | **High** — this is the project's own designated "supersedes all previous checkpoints" document; leaving it wrong about the single highest-severity historical risk item is exactly the kind of drift the register's own discipline exists to prevent. |
| C2 | **`KNOWN_RISKS_AND_DECISIONS.md` item 4** ("Performance/Growth Campaign reward values & targets") still opens with *"Issue: No real Performance/Growth Campaign engine exists... Current behavior: N/A — not built."* This is false as written today: `PerformanceCampaign`/`PerformanceCampaignService`, the `admin.performance-campaigns.index` route, and `PerformanceCampaignEngineTest` (34 tests) have existed since mission Phase 1 of this same segment. | `CURRENT_MASTER_CHECKPOINT.md §2` item 1; `FINAL_ADMIN_CAPABILITY_MATRIX.md §B` row "Performance/Growth Campaigns... [IMPLEMENTED] [VERIFIED] — Phase 1, 34 tests"; `routes/admin.php` confirms the route exists. | **Medium** — the *engine* claim is stale/wrong; the underlying business decision the item is actually gated on (real reward values/targets, not the architecture) is still genuinely open and correctly unresolved. The item's own title is still accurate; only its "Issue"/"Current behavior" prose is wrong. |
| C3 | **`KNOWN_RISKS_AND_DECISIONS.md` item 5** ("Tips / Compensation rate structures") opens with *"Issue: No tip, waiting-compensation, rain-compensation, overtime, peak, or night-compensation model exists anywhere in the schema. Current behavior: N/A — not built."* Also false as written: mission Phase 5 (this same segment) built exactly this — `booking_compensations` table, auto-computed overtime/night/peak, admin-triggered rain/waiting, tips moving real money via `WalletService`, all rates `Setting`-driven and defaulting to `0`. | `CURRENT_MASTER_CHECKPOINT.md §2` item 3 ("cd174cc — Tips/waiting/rain/overtime/peak/night compensation. 22 tests."); `FINAL_ADMIN_CAPABILITY_MATRIX.md §B` "Compensation... [IMPLEMENTED] [VERIFIED] — Phase 5." | **Medium** — same shape as C2: the engine exists, only the real *rate values* remain the genuinely open decision. Item 5's own closing "Phase 13 evidence" paragraph actually gets this right (talks about rates, not the engine) — only the opening "Issue"/"Current behavior" lines are stale. |
| C4 | **`KNOWN_RISKS_AND_DECISIONS.md` item 12** ("Flash Sale × Coupon × Badge stacking rules") opens with *"(Anticipatory — logged before Flash Sale engine is built.)... Current behavior: N/A — not built yet."* Both the Flash Sale engine (`FlashSale` model, `admin.flash-sales.index`, `FlashSaleEngineTest`) and the Badge engine (`Badges\Manage`, `BadgeEngineTest`) have existed since **before this 20-phase mission's own baseline** (`6b7c36e`) — built in an earlier session referenced in `PROJECT_CURRENT_STATE.md`'s own addendum #2. This staleness predates this mission entirely; 18 phases had the opportunity to correct it and didn't, because nothing in this mission's own phases happened to touch Flash Sales. | `PROJECT_CURRENT_STATE.md` line 51 ("built the Universal Badge Engine... built the Flash Sale Engine... 42 tests"); `FINAL_ADMIN_CAPABILITY_MATRIX.md §A` lists both `Badges\Manage` and `FlashSales\Manage` as real, gated, tested screens. | **Low-Medium** — same shape again: the engines exist, the actual open question (a real stacking rule between Flash Sale/Coupon/Plan discounts) is still genuinely unanswered and the item's proposed safe default (`exclusive`, no stacking) has **not been independently confirmed as actually implemented in code this pass** — flagged as open item BD-4 below rather than assumed either way. |
| C5 | **Minor count discrepancy, not a real contradiction.** `KNOWN_RISKS_AND_DECISIONS.md` item 26 says "roughly 16" admin Blade views hardcode `₹`. A fresh repo-wide grep this session found **14** files (list in TECH-1 below), not 16. Immaterial to the finding's substance (the gap is real either way) but the exact count should be corrected when the item is next touched. | `grep -rl "₹" resources/views` (excluding `documents/`, which is correctly the one real consumer). | **Low** — cosmetic accuracy only. |

**Recommendation:** correct C1–C5 as a first, near-zero-risk Phase 21 housekeeping item (DOC-1 below) before any other work — a future session should not have to re-discover that these engines already exist. **Status: ✅ COMPLETE — commit `dbe6f4e`.**

---

## 1. COMPLETE — verified, no further work required

Listed for completeness so Phase 21 doesn't re-audit these. Each was independently re-verified against actual code at least once across Phases 16–20, not just asserted.

| Capability | Evidence |
|---|---|
| Print/document engine (invoices/receipts, real PDF, idempotent numbering) | Phase 7; `barryvdh/laravel-dompdf` present in `composer.json`; `DocumentService` |
| API HTTP-level test coverage, all 24 pre-existing business-logic routes | Phase 16; 6 new test files closed the remaining 20/24 |
| General-purpose API rate limiting (60/min per user) | Phase 16; `AppServiceProvider::boot()` `RateLimiter::for('api', ...)`, `ApiRateLimitTest` |
| API-layer IDOR audit, 17 controllers / 40+ endpoints | Phase 16; every controller re-checks ownership server-side |
| Admin-panel RBAC — 33/33 screens/views, view-gate + sidebar-sync + test coverage | Phase 19, `FINAL_ADMIN_CAPABILITY_MATRIX.md §A`; independently re-verified this session (`mount()` reads, sidebar `$navItems` diff, `ps`/`grep` spot-checks) |
| `LoyaltyService::redeem()` unlocked-balance race | Phase 15; fixed via `lockForUpdate()`, 2 boundary tests |
| `ReconciliationService::walletBalanceMismatches()` N+1 | Phase 18; rewritten to one grouped SQL aggregate, regression test proves flat query cost |
| 9 hot-column DB indexes across 8 tables | Phase 18; evidence-based, same methodology as the original `bookings` index migration |
| `CampaignNotification`/`KycNotification` bulk-send blocking | Phase 18; both now `implements ShouldQueue`, each fix independently verified to fail without it (`git stash` test) |
| Mass-assignment (`$guarded = []`), leftover debug statements, hardcoded secrets | Phase 20; re-scanned, zero matches, confirmed again this session |
| Full 162-migration chain integrity | Proven by every one of 620 tests rebuilding a fresh `:memory:` SQLite DB via `RefreshDatabase` |
| Production `APP_DEBUG` | **RESOLVED** 2026-08-15, commit `ed26e48`; `.env`/`php artisan about`/live HTTP 404 all confirm `false`/`OFF`/no debug fields — see `KNOWN_RISKS_AND_DECISIONS.md` item 25 for the full trail |
| `referrals`/`performance_campaign_participants` cascade-delete (item 23) | Confirmed unreachable — no code path hard-deletes a `User`/`PerformanceCampaign`. No action needed unless a future feature reopens the path. |
| Performance/Growth Campaign engine, Tips/Compensation engine, Flash Sale engine, Badge engine (the *architecture*, not the values) | See C2–C4 above — these exist and are tested; only their real business values remain open (BD-1, BD-2 below) |

---

## 2. OPEN items — master index

Every item below has full detail in its primary category section (§3–§7). `Cat.` = primary category; items tagged `+TECH` also require engineering work beyond the decision itself.

| ID | Priority | Item | Cat. | Code change required? |
|---|---|---|---|---|
| DOC-1 | **P0** | Correct C1–C5 documentation staleness — ✅ COMPLETE (`dbe6f4e`) | Production prereq | No (docs only) |
| BD-8 | **P0** | Real SMS/push provider integration | Business decision +TECH | **Yes**, once vendor chosen |
| TECH-1 | **P0** | `Payouts\Manage` has no row-level franchise scope — ✅ COMPLETE (`cfb1fa6`) | Technical | **Yes** |
| BD-17 | **P1** | Terms & Conditions / Privacy Policy content | Business decision | No (content only; TECH-5 below for signup wiring) |
| BD-24 | **P1** | Sanctum token expiration + device revoke-list | Business decision +TECH | **Yes**, once policy decided |
| TECH-2 | **P1** | Admin panel hardcodes `₹` instead of the built `Setting` — ✅ COMPLETE (`4c1db7c`) | Technical | **Yes** |
| TECH-3 | **P1** | Admin panel displays every timestamp in raw UTC — ✅ COMPLETE for franchise-scoped screens (`ba4f72e`); 4 deferred timestamps still open | Technical | **Yes** |
| TECH-4 | **P1** | No admin chat-moderation screen — ✅ COMPLETE, Option A (read-only) only (`e431667`); Option B (moderation) still open | Technical | **Yes** |
| ENV-1 | **P1** | No database backup tooling | Environment | **Yes**, once policy decided |
| BD-16 | **P1** | `partner.workers.assign` — no Partner-facing authorization model decided | Business decision | Possibly, once decided |
| TECH-6 | **P1** | Admin UI has no design system | Technical | **Yes** (large) |
| BD-11 | **P1** | `payment_methods` vs `payment.*_enabled` consolidation | Business decision | Possibly, once decided |
| TECH-7 | **P2** | `CampaignService` audience resolution not chunked — ✅ COMPLETE (`df4e186`) | Technical | **Yes** (small) |
| BD-1 | **P2** | Referral reward values | Business decision | No |
| BD-2 | **P2** | Cross-actor referral scope | Business decision | Yes, once decided |
| BD-3 | **P2** | Anti-fraud signals for referrals | Business decision | Yes, once decided |
| BD-4 | **P2** | Campaign reward values/targets (engine exists, values don't) | Business decision | No |
| BD-5 | **P2** | Tips/Compensation rate values (engine exists, rates don't) | Business decision | No |
| BD-6 | **P2** | Worker compensation model | Business decision | Yes, once decided |
| BD-7 | **P2** | Coupon system customer-facing launch | Business decision | Yes, once decided |
| BD-9 | **P2** | Second payment provider | Business decision | Yes, once decided |
| BD-10 | **P2** | Commission clawback on refund (currently unreachable) | Business decision | No, until reachable |
| BD-12 | **P2** | Flash Sale × Coupon × Badge stacking rule | Business decision | Verify/possibly yes |
| BD-13 | **P2** | 30-day KYC deadline scope for Riders/Workers | Business decision | Yes, once decided |
| BD-14 | **P2** | Per-country KYC document requirements | Business decision | No (admin-editable already) |
| BD-18 | **P2** | Multi-language / locale content support | Business decision | Yes, once decided (large) |
| BD-20 | **P2** | Vendors/Menus/Products import | Business decision | Yes, once decided (large) |
| TECH-8 | **P2** | Soft-deleted `Service` cover-image purge | Technical | Yes, whenever a purge job is built |
| TEST-1 | **P2** | Browser-driven E2E test suite | Testing | Yes (large) |
| TEST-2 | **P2** | QA web frontend | Testing | Yes (large) |
| ENV-2 | **P2** | True multi-process load/concurrency testing | Environment | No (blocked on infra) |
| ENV-3 | **P2** | Live performance profiling under real load | Environment | No (blocked on infra, same as ENV-2) |

---

## 3. BUSINESS DECISIONS

Items requiring an explicit product/business/legal call before engineering can proceed (or before the item can be considered closed even if a safe default exists today). **No decisions are being asked for in this document** — each is stated as a question for the record.

### BD-1 — Referral reward values
- **Priority:** P2
- **Exact problem:** `ReferralService::qualifyFromCompletedBooking()` reads `referral.reward_type`/`reward_amount`/`reward_points` via `Setting::get()` with placeholder fallback defaults (`wallet`/`50`/`100`) never deliberately chosen.
- **Current implementation status:** COMPLETE architecture, PLACEHOLDER values. Fully configurable per-scope via `Setting`.
- **Affected modules/files:** `app/Services/ReferralService.php`, `Setting` table keys `referral.reward_*`.
- **Dependency:** None technical.
- **Recommended action:** Product/marketing decides real referral reward amount/points, optionally per country/franchise; set via existing `Settings\Manage` UI.
- **Acceptance criteria:** A real, deliberate value is set at the appropriate scope(s); no code change needed to apply it.
- **Code changes required:** No.

### BD-2 — Cross-actor referral scope
- **Priority:** P2
- **Exact problem:** `Referral` model only supports Customer↔Customer. Partner↔Customer/Worker, Worker↔Customer have no qualification logic.
- **Current implementation status:** NOT BUILT for non-Customer↔Customer pairs.
- **Affected modules/files:** `app/Services/ReferralService.php`, `app/Models/Referral.php`.
- **Dependency:** BD-1 (reward values) if new pairs are added.
- **Recommended action:** Decide which actor-pair combinations ship in v1 and what "qualifying transaction" means for a referred Partner/Worker (e.g., first accepted job, not booking).
- **Acceptance criteria:** A documented decision naming in-scope pairs + qualifying-transaction definition per pair.
- **Code changes required:** Yes, once decided.

### BD-3 — Anti-fraud signals for referrals
- **Priority:** P2
- **Exact problem:** No automatic device/velocity/duplicate-account detection; only manual admin flagging exists (`ReferralService::flagAsFraud()`).
- **Current implementation status:** PARTIAL — manual review tooling complete; automatic detection not built.
- **Affected modules/files:** `app/Services/ReferralService.php`, `app/Livewire/Loyalty/Index.php` (Referrals tab).
- **Dependency:** None.
- **Recommended action:** Risk/product team defines real fraud signal thresholds.
- **Acceptance criteria:** Documented threshold(s); manual-review-only remains the safe default until then.
- **Code changes required:** Yes, once decided.

### BD-4 — Campaign reward values/targets (engine exists — see contradiction C2)
- **Priority:** P2
- **Exact problem:** The Performance/Growth Campaign *engine* is fully built and tested (Phase 1, 34 tests) — what remains open is only the real target metrics, ranking rules, and reward amounts for actual campaigns, which are commercial decisions each time a campaign is created.
- **Current implementation status:** COMPLETE architecture. Per-campaign values are admin-configured at creation time, not hardcoded, so there is no single "default" to set — each real campaign needs its own real numbers when launched.
- **Affected modules/files:** `app/Models/PerformanceCampaign.php`, `app/Services/PerformanceCampaignService.php`, `app/Livewire/PerformanceCampaigns/Manage.php`.
- **Dependency:** None technical.
- **Recommended action:** Correct `KNOWN_RISKS_AND_DECISIONS.md` item 4's stale "not built" framing (DOC-1); no other action needed until an actual campaign is launched with real numbers.
- **Acceptance criteria:** Item 4 in the register accurately describes the architecture as built; future campaigns are created with deliberate, not placeholder, values.
- **Code changes required:** No.

### BD-5 — Tips/Compensation rate values (engine exists — see contradiction C3)
- **Priority:** P2
- **Exact problem:** Same shape as BD-4 — the compensation engine (tips, overtime/night/peak auto-computed, rain/waiting admin-triggered) is fully built (Phase 5, 22 tests) with every rate defaulting to `0` (no effect) until a `Setting` is configured. The actual commercial rates were never decided.
- **Current implementation status:** COMPLETE architecture, all rates at safe `0` defaults.
- **Affected modules/files:** `app/Actions/CompleteBookingAction.php`, `Setting` keys under `compensation.*`.
- **Dependency:** None technical.
- **Recommended action:** Correct item 5's stale framing (DOC-1); business decides real ₹/minute waiting rate, rain surcharge %, overtime multiplier, etc., per country/franchise if it varies.
- **Acceptance criteria:** Real rates set via `Settings\Manage`'s Compensation tab.
- **Code changes required:** No.

### BD-6 — Worker compensation model
- **Priority:** P2
- **Exact problem:** No independent base-pay/commission model exists for FieldWorkers (distinct from Provider commission via `CommissionService`); Workers currently earn only through Provider delegation.
- **Current implementation status:** NOT BUILT. No code path assumes this exists (confirmed no live risk).
- **Affected modules/files:** Worker/Rider architecture (`app/Models/FieldWorker.php`), `PayoutService` (has no FieldWorker payout path at all today).
- **Dependency:** Blocks BD-13 (30-day KYC deadline for Workers) — that item explicitly can't be enforced meaningfully until a Worker payout path exists.
- **Recommended action:** Full worker compensation model definition needed before any engineering.
- **Acceptance criteria:** A documented model (or an explicit decision that Workers never get independent compensation, staying delegation-only).
- **Code changes required:** Yes, once decided — no safe partial architecture exists yet because the shape is undefined.

### BD-7 — Coupon system's customer-facing launch decision
- **Priority:** P2
- **Exact problem:** `coupons`/`coupon_usages` schema and FK integrity exist; no customer-facing redemption flow is wired.
- **Current implementation status:** DORMANT infrastructure, correct as a deliberate non-decision.
- **Affected modules/files:** `app/Models/Coupon.php`, Bookings flow, potentially the Flash Sale engine (see BD-12).
- **Dependency:** BD-12 (stacking rule) if launched alongside Flash Sales.
- **Recommended action:** Decide whether/when to launch, and under what stacking constraints.
- **Acceptance criteria:** Launch decision documented; if "yes," a redemption flow gets built against the existing schema.
- **Code changes required:** Yes, once decided.

### BD-8 — Real SMS/push provider ⚠️ P0
- **Priority:** **P0** — this is the single highest-priority open BUSINESS DECISION in this backlog.
- **Exact problem:** `LogSmsAdapter`/`LogPushAdapter` are the only bound implementations. OTP codes and push payloads are written to the server log only — **no real user can receive a login or booking OTP by SMS today.**
- **Current implementation status:** Architecture COMPLETE (`SmsAdapter`/`PushAdapter` contracts exist and are ready for a real binding the moment credentials exist); actual delivery is dev/QA-only.
- **Affected modules/files:** `app/Providers/AppServiceProvider.php` (adapter binding), `app/Notifications/Adapters/LogSmsAdapter.php`, `LogPushAdapter.php`, all OTP flows (`OtpService`, `BookingOtpNotification`).
- **Dependency:** None technical — purely vendor selection + procurement.
- **Recommended action:** Choose a real SMS/push vendor and obtain credentials. **Real historical precedent exists** (Phase 13 evidence): a prior 1CallFix production database shows Firebase (OTP, real project id `onecallfix-6b538`) + MSG91 + GatewayAPI (SMS) + FCM (push) were the actual prior choice — the embedded server auth token is expired, so credentials need re-procuring, but the vendor decision itself has real precedent unlike almost every other item in this register.
- **Acceptance criteria:** A real `SmsAdapter`/`PushAdapter` implementation is bound in `AppServiceProvider`, and a real OTP is confirmed delivered to a real phone in a controlled test.
- **Code changes required:** **Yes**, once a vendor is chosen — a new adapter class implementing the existing contract, config for credentials, and the binding switch. This is the one BD item this backlog explicitly flags as **launch-blocking**: without it, no real customer can complete phone-based login or booking OTP at all.

### BD-9 — Second payment provider
- **Priority:** P2
- **Exact problem:** Only Razorpay is bound to the `PaymentGateway` contract.
- **Current implementation status:** DELIBERATE single-provider state, not a gap — confirmed by Phase 13 evidence as the real prior-deployment posture too (only Cash/RazorPay/Wallet were ever active out of 13 configured gateways in the 1.8.10 dump).
- **Affected modules/files:** `app/Services/Payments/RazorpayService.php`, `PaymentGateway` contract.
- **Dependency:** None.
- **Recommended action:** None unless/until a second provider is genuinely needed.
- **Acceptance criteria:** N/A until decided.
- **Code changes required:** Yes, once decided.

### BD-10 — Commission clawback on refund (currently unreachable)
- **Priority:** P2
- **Exact problem:** No commission reversal exists if a completed (commission-applied) booking is later refunded — but `AdminCancelBookingAction` explicitly refuses to cancel an already-`completed` booking, so this path is **not currently reachable**.
- **Current implementation status:** Confirmed unreachable by direct code read (both actions), not assumed.
- **Affected modules/files:** `app/Actions/AdminCancelBookingAction.php`, `app/Services/CommissionService.php`, `app/Services/CancellationService.php`.
- **Dependency:** Only relevant if/when a post-completion refund/dispute path is ever built.
- **Recommended action:** No action now. If a post-completion refund path is ever proposed, decide the clawback policy (from provider, franchise, platform, or split) before building it — and resolve the parallel referral-reward-clawback question (Phase 3 finding) at the same time.
- **Acceptance criteria:** N/A until the triggering feature is proposed.
- **Code changes required:** No, until reachable.

### BD-11 — `payment_methods` / `payment_accounts` admin UI consolidation
- **Priority:** P1
- **Exact problem:** `payment_methods` table exists at the model level with no admin UI, appearing to duplicate the `payment.*_enabled` Settings toggles built in Phase 11.
- **Current implementation status:** `payment_accounts` (a **different** table — settlement/payout accounts) has a complete write path since Phase 9; do not confuse the two. `payment_methods` specifically remains unexposed.
- **Affected modules/files:** `app/Models/PaymentMethod.php`, `Setting` keys `payment.*_enabled`.
- **Dependency:** None technical.
- **Recommended action:** Decide whether `payment_methods` should be retired in favor of the Settings toggles, or serves a distinct purpose. **Phase 13 evidence materially favors retiring the Settings toggles**: the real 1.8.10 database used `payment_methods` as the sole source of truth (enablement + credentials + per-method behavior flags a boolean can't express) with no parallel setting anywhere, plus a `payment_method_vendor` pivot for real per-franchise availability. Not executed unilaterally since it's a real behavior change to the live New-Booking-modal flow.
- **Acceptance criteria:** A documented consolidation decision; the losing mechanism retired cleanly.
- **Code changes required:** Possibly, once decided.

### BD-12 — Flash Sale × Coupon × Badge stacking rules
- **Priority:** P2
- **Exact problem:** No decided rule for whether a flash-sale price can stack with a coupon or a Plan Engine member discount (see contradiction C4 — the engines this item anticipated now exist).
- **Current implementation status (verified 2026-08-16, read-only, no code changed):** Confirmed `App\Models\FlashSale` has **no `stacking_strategy` column or equivalent guard at all**, unlike `Plan`, which does — there is no enforced stacking rule in code today. `FlashSaleService::priceFor()`'s own docblock documents an informal default-to-no-stacking against `FranchiseServicePricing` (a flash sale price "wins outright rather than stacking with the franchise override"), but that is the pricing-cascade layering, not a real stacking guard against Plan discounts. **More significantly, this verification also found `FlashSaleService::priceFor()`/`::redeem()` are never actually called from the real booking-creation path at all (`CreateBookingAction` only ever applies the Plan Engine's own entitlement discount)** — so the Flash-Sale-vs-Plan stacking question this item asks about cannot currently occur in a real booking either way. Logged as its own new finding, `KNOWN_RISKS_AND_DECISIONS.md` item 29, since it's a materially different (and larger) gap than what this item originally asked to verify.
- **Affected modules/files:** `app/Models/FlashSale.php`, `app/Models/Plan.php` (`stacking_strategy`), Coupons (dormant, BD-7), `app/Actions/CreateBookingAction.php` (item 29).
- **Dependency:** BD-7 if coupons launch. Item 29 (Flash Sale wiring) is now itself a prerequisite for this stacking question to have any live-booking consequence.
- **Recommended action:** Decide the real stacking policy (Flash Sale × Plan, since Coupons remain dormant) — needed before item 29's wiring gap can be closed correctly, not just mechanically.
- **Acceptance criteria:** Documented current behavior (done, above) + a real decision once relevant.
- **Code changes required:** To verify: no (done). To change the policy: possibly, and only meaningful once item 29 is also addressed.

### BD-13 — 30-day KYC deadline / withdrawal restriction for Riders/Workers
- **Priority:** P2
- **Exact problem:** The 30-day deadline/withdrawal-restriction policy is built and enforced for Partners only; FieldWorkers have no deadline, no reminders, and (since there's no FieldWorker payout path at all — see BD-6) no enforcement point to attach to.
- **Current implementation status:** Low risk today specifically because BD-6 (Worker payout path) doesn't exist yet.
- **Affected modules/files:** `app/Services/Kyc/*`, `app/Models/FieldWorker.php`.
- **Dependency:** **BD-6** — this can't become a real enforcement gap until a Worker payout path exists.
- **Recommended action:** Decide whether/how the identical policy extends to Workers once BD-6 is resolved.
- **Acceptance criteria:** A documented policy; the existing `KycWithdrawalPolicyService`/`kyc_withdrawal_exceptions` architecture is directly reusable once decided.
- **Code changes required:** Yes, once decided (and once BD-6 exists).

### BD-14 — Per-country KYC document requirements
- **Priority:** P2
- **Exact problem:** `kyc_document_requirements` seeded one global default set for every country.
- **Current implementation status:** SAFE DEFAULT in place; admin-correctable per-country at any time (no dedicated admin UI for editing it yet, only direct DB access).
- **Affected modules/files:** `app/Services/Kyc/KycDocumentService.php::requirementsFor()`.
- **Dependency:** None.
- **Recommended action:** Confirm/adjust required-document list per country as real legal/compliance requirements become known.
- **Acceptance criteria:** Real per-country overrides added as needed.
- **Code changes required:** No (structurally ready) — unless a real admin UI for editing this is also wanted, which would be a small TECH addition.

### BD-16 — `partner.workers.assign` has no permission check
- **Priority:** P1
- **Exact problem:** `PartnerWorkerController::assignBooking()` performs zero `hasPermission()` check — confirmed by direct code read this session (no `hasPermission` call anywhere in the file). It relies entirely on ownership checks (`$request->user()->providerProfile` + `AssignBookingToWorkerAction`'s own booking/worker ownership validation), which are real and correctly enforced.
- **Current implementation status:** SAFE from cross-partner IDOR today (ownership is the real boundary); the *permission-based* layer the migration anticipated (`2026_08_11_044000_seed_worker_foundation_permissions.php`) was never wired.
- **Affected modules/files:** `app/Http/Controllers/API/PartnerWorkerController.php::assignBooking()`.
- **Dependency:** Whether Partner/Worker-facing mobile actions should ever go through the admin `AuthorizationService`/`RoleAssignment` system, or need a separate authorization model — no such model exists today, and no real Provider account has ever been granted an RBAC role assignment.
- **Recommended action:** Decide the authorization-model question. **Phase 13 evidence:** the real 1.8.10 database used one unified permission system for every actor (admin and Provider/Driver alike) — real precedent for extending `AuthorizationService` rather than building a parallel system, but not proof of the right default-grant policy for existing accounts.
- **Acceptance criteria:** A documented authorization-model decision; `partner.workers.assign` (and 5 sibling seeded-but-unused `worker.*` permissions) wired accordingly without locking out real providers.
- **Code changes required:** Possibly, once decided.

### BD-17 — Terms & Conditions / Privacy Policy content
- **Priority:** **P1**
- **Exact problem:** No Terms & Conditions or Privacy Policy content exists anywhere. No signup/login flow references an "I agree" checkbox or link.
- **Current implementation status:** The admin CRUD (`Cms\Manage`) and public read API (`GET /api/pages/{slug}`) are both fully ready to serve this content the moment it exists — nothing has created it.
- **Affected modules/files:** `Cms\Manage`, `content_pages` table, future signup flow (does not exist yet either — see TECH-5).
- **Dependency:** Real legal review/sign-off; a signup flow to reference the content from.
- **Recommended action:** Get legal/compliance sign-off on real Terms & Conditions / Privacy Policy text, then create the pages via the existing admin screen. **Escalated stakes (Phase 13 evidence):** the real 1CallFix 1.8.10 deployment never wrote this content either, even with a live Play Store app shipping — this is a standing company-level gap, not a rebuild omission.
- **Acceptance criteria:** Real, legally-reviewed content exists as published pages; a real signup flow references and requires acceptance of it.
- **Code changes required:** No for the content itself; yes for wiring an acceptance requirement into a signup flow once one exists (see TECH-5).

### BD-18 — Multi-language / locale content support
- **Priority:** P2
- **Exact problem:** No locale/language column exists anywhere in the content layer; `users.preferred_language` is collected but never read (zero consumers, confirmed by grep).
- **Current implementation status:** Single-locale (English) throughout, by design, no structural blocker to adding translations later.
- **Affected modules/files:** `content_pages`, `faqs`, `service_categories`, `service_subcategories`, `services`, `banners`, and potentially every other content-bearing model.
- **Dependency:** A market-expansion decision.
- **Recommended action:** Decide whether/when multi-language is needed and which translation architecture to use. **Phase 13 evidence:** the real 1.8.10 database already used a JSON-per-locale column pattern (matching `spatie/laravel-translatable`'s convention) — but only ever populated `en` in any row, across a real, contemplated Ghana expansion (`appCountryCode='INTERNATIONAL,GH'`) that never got non-English content either. Real precedent for the pattern, not proof it's urgent.
- **Acceptance criteria:** A documented decision on scope and architecture.
- **Code changes required:** Yes, once decided — large, spans many models.

### BD-20 — Vendors/Menus/Products import
- **Priority:** P2
- **Exact problem:** 1CallFix has no `vendors`/`menus`/`products` tables at all; the reference product's own importer covers a materially different, richer business entity than anything in the current architecture.
- **Current implementation status:** No target schema exists — there is nothing to import into.
- **Affected modules/files:** N/A today; would be a new vertical.
- **Dependency:** Whether 1CallFix ever builds a multi-vendor marketplace vertical (Food/Grocery/Pharmacy-style).
- **Recommended action:** No action unless that vertical decision is made. The real, working `App\Services\Catalog\CatalogImporter` engine (built for Categories/Subcategories/Services) is directly reusable as a starting point the day a target schema exists.
- **Acceptance criteria:** N/A until a vertical decision is made.
- **Code changes required:** Yes, once decided — large, new vertical.

---

## 4. TECHNICAL implementation items

Items that are pure engineering — no business decision is blocking them, or the decision has already been made and only the build remains.

### TECH-1 — `Payouts\Manage` has no row-level franchise scope ⚠️ P0
- **Status:** ✅ **COMPLETE** — commit `cfb1fa6`. `AuthorizationService::scopeQuery()` applied to `Payouts\Manage::render()` (list visibility) and to every write action (`markProcessing()`, `confirmMarkPaid()`, `markFailed()` — each re-fetches the target payout and re-checks its own resolved scope before acting, not just at screen-entry). `PayoutsExport` now takes the acting viewer and scopes identically via `AuthorizationService::visibleAmong()` against `Payout::authorizationScopeHint()` (export scope). Regression coverage: 7 new tests in `RowLevelScopeAuthorizationTest.php` plus 2 in `DataExportTest.php`, verified via `git stash` to genuinely fail without the fix. **Scope note:** this closes the Payouts row-level gap only — it does not claim the separate `PaymentAccount` authorization surface is resolved.
- **Priority:** **P0**
- **Exact problem:** `Payouts\Manage::render()` — confirmed this session at `app/Livewire/Payouts/Manage.php:247` — runs `Payout::latest()->paginate(15)` with **no** `AuthorizationService::scopeQuery()` filter. `Commissions\Index` (the closest sibling screen) correctly scopes by franchise/zone/city/country through its booking relation; `Payouts\Manage` does not. A franchise-scoped `payouts.manage` grant can therefore view (and, via the Phase 14 export, download) **every** franchise's payout requests and banking details, not just their own.
- **Current implementation status:** Real, live authorization gap. `payouts.manage` itself is correctly permission-gated at the screen-entry level (view-gate confirmed present and tested, `FINAL_ADMIN_CAPABILITY_MATRIX.md §A`) — the gap is purely row-level.
- **Affected modules/files:** `app/Livewire/Payouts/Manage.php` (`render()`, line ~247), `app/Exports/PayoutsExport.php` (matches the screen's current unscoped behavior deliberately, per its own docblock — will need updating in the same pass), `app/Models/Payout.php` (`payee_type`/`payee_id` polymorphic — no direct `franchise_id` column, so scoping must resolve through `payee` → `Provider`/`User` → franchise, the same shape `Commissions\Index` already solves through its own relation).
- **Dependency:** None — no business decision required (the register's own item 22 says so explicitly).
- **Recommended action:** Add the same `AuthorizationService::scopeQuery(Payout::query(), auth()->user(), 'payouts.manage', $columns)` pattern `Commissions\Index` already uses, resolving `$columns` through the polymorphic `payee` relation. Apply the identical scope to `PayoutsExport`.
- **Acceptance criteria:** A franchise-scoped `payouts.manage` holder sees and can export only their own franchise's payouts; a global-scoped holder is unaffected; a new regression test proves the cross-franchise denial (mirroring `Commissions\Index`'s own existing scope test).
- **Code changes required:** **Yes.**

### TECH-2 — Admin panel hardcodes `₹` instead of the built `Setting`
- **Status:** ✅ **COMPLETE** — commit `4c1db7c`. All 14 listed views now read `Setting::get('locale.currency_symbol', ...)` at each screen's own correct scope (12 via a new `currencySymbol`/`getCurrencySymbolProperty()` Livewire property, `Settings\Manage` reused its pre-existing `$localeCurrencySymbol`); no hardcoded `₹` remains in any of the 14. Regression coverage: 16 new tests in `CurrencySymbolDisplayTest.php`, using a distinctive `¥` symbol to prove the Setting is actually being read, not coincidentally matching its own default. **Scope note:** this closes display consistency across these 14 screens only — it is not a claim of full multi-currency readiness (formatting/rounding/exchange-rate conversion were out of scope and untouched).
- **Priority:** P1
- **Exact problem:** `Setting::get('locale.currency_symbol', '₹', $scope)` is real, admin-editable, scope-cascaded — but only `DocumentService` (invoices/receipts) reads it.
- **Current implementation status:** Confirmed this session via fresh repo-wide grep: **14** admin Blade views hardcode the literal `₹` (not "roughly 16" as the register currently says — see contradiction C5). Exact list:
  - `resources/views/livewire/commissions/index.blade.php`
  - `resources/views/livewire/customers/index.blade.php`
  - `resources/views/livewire/customers/show.blade.php`
  - `resources/views/livewire/flash-sales/manage.blade.php`
  - `resources/views/livewire/loyalty/index.blade.php`
  - `resources/views/livewire/notification-center/manage.blade.php`
  - `resources/views/livewire/operations/health.blade.php`
  - `resources/views/livewire/payments/index.blade.php`
  - `resources/views/livewire/payouts/manage.blade.php`
  - `resources/views/livewire/performance-campaigns/manage.blade.php`
  - `resources/views/livewire/plans/manage.blade.php`
  - `resources/views/livewire/settings/manage.blade.php`
  - `resources/views/livewire/subscriptions/index.blade.php`
  - `resources/views/livewire/wallet-ledger/index.blade.php`
- **Dependency:** None.
- **Recommended action:** Swap each hardcoded `₹` for the existing `Setting::get('locale.currency_symbol', ...)` call, using each screen's own correct scope (same pattern `DocumentService` already uses). Given zero existing display-string test coverage protects against a mistake, add a lightweight rendered-output assertion per screen as part of the same change, not a separate follow-up.
- **Acceptance criteria:** All 14 views read the Setting; changing `locale.currency_symbol` at any scope changes every screen consistently, not just invoices.
- **Code changes required:** **Yes** — bounded, mechanical, 14 files.

### TECH-3 — Admin panel displays every timestamp in raw UTC
- **Status:** ✅ **COMPLETE for franchise-scoped operational screens** — commit `ba4f72e`. New `app/Services/TimezoneResolver.php` resolves each row's own `franchise → country → default_timezone`, falling back to `config('app.timezone')` when no franchise/country applies — the design choice this item itself named as open (a helper reading the row's own franchise chain) is what was adopted. Applied to Banners, Bookings, Commissions, Customers, Flash Sales, KYC Support Requests, Loyalty, Payments, Providers, Subscriptions, Wallet Ledger, Workers, and Operations/Health's dispatch/stuck-booking timestamps. Deliberately still UTC (franchise-agnostic system/audit data, not an oversight): `failed_jobs`, `scheduled_task_runs`, `payment_webhook_logs`, `NotificationLog.sent_at`. Regression coverage: 23 new tests in `TimezoneDisplayTest.php`, including a UTC-midnight boundary case, a stored-value-not-mutated assertion, and 2 N+1 guards.
- **Deferred findings discovered during this pass (not fixed, no policy decided):** `PerformanceCampaign.starts_at`/`ends_at`, `FlashSale.starts_at`/`ends_at`, `BadgeAssignment.expires_at`, `NotificationMeeting.starts_at` — all found to be scope-shaped timestamps during the screen-by-screen inspection, but out of this item's authorized scope. Whether these should convert to franchise-local time, stay UTC, or use another policy was not decided or assumed. See `KNOWN_RISKS_AND_DECISIONS.md` item 27 for the same note preserved in the register.
- **Priority:** P1
- **Exact problem:** `countries.default_timezone` is correctly used by `DocumentService` for invoice numbering, but nothing else converts a timestamp to a viewer's local time. Confirmed this session: zero matches for `setTimezone(`/`->tz(` outside `DocumentService` across `resources/views` and `app/Livewire`. Real production data is India-based (`Asia/Kolkata`), so admins see every timestamp 5.5 hours behind their own wall-clock time today.
- **Current implementation status:** Present-day usability gap, not a future-country hypothetical.
- **Affected modules/files:** Every Carbon `->format(...)` call across Bookings, Payouts, Commissions, Wallet Ledger, Customers, Operations, Notification Center, Settings, and more — a wide surface, not a short file list.
- **Dependency:** A design decision on **how** to convert: a global Blade helper/Carbon macro reading `auth()->user()->franchise->country->default_timezone` vs. a simpler single-tenant `config('app.display_timezone')` (only one country is live today). This is a technical design choice, not a business decision — noted as such in the register.
- **Recommended action:** Decide the conversion pattern deliberately (don't guess which the eventual multi-country UI should standardize on), then apply it. Given the wide, currently-zero-test-coverage surface, this should ship with new display-string assertions covering the pattern's correctness, not just a drive-by retrofit.
- **Acceptance criteria:** Every admin-visible timestamp renders in the viewer's/franchise's local time with a real test proving at least one screen's boundary case (e.g., a booking near UTC midnight rendering the correct local calendar day).
- **Code changes required:** **Yes** — wide surface, needs a deliberate design decision on the conversion pattern first.

### TECH-4 — No admin chat-moderation screen
- **Status:** ✅ **COMPLETE, Option A only (read-only admin chat viewer)** — commit `e431667`. New `chat.view` permission (super_admin only by default), `app/Livewire/Chat/Manage.php` (`/admin/chat`), row-level franchise/zone scope via `AuthorizationService::scopeQuery()`, attachment retrieval independently re-authorized per request (`ChatAttachmentController`, 404-not-403 pattern), every conversation view activity-logged. Regression coverage: 19 new tests in `AdminChatViewerTest.php`; existing customer/partner/worker `ChatApiTest` (16 tests) unchanged and still green. **Remaining open decision, NOT resolved by this pass:** whether admin intervention/moderation (send/edit/hide/delete/block/flag/report/suspend as admin) is wanted at all, and if so which actions and which roles — deliberately not built, no `chat.moderate` permission exists, no schema change was made. See `KNOWN_RISKS_AND_DECISIONS.md` item 15 for the preserved decision record.
- **Priority:** P1
- **Exact problem:** Universal Chat (`ChatService`/`ChatController`) is a fully working Customer↔Partner/Worker API with **zero** admin-facing surface — confirmed this session, no `App\Livewire\Chat\*` component and no chat route exists anywhere in `routes/admin.php`.
- **Current implementation status:** Conversations are fully functional between real actors but invisible to admin/support; the only escape hatch is a raw DB query.
- **Affected modules/files:** New: `app/Livewire/Chat/*` (doesn't exist yet), likely under Operations/Troubleshoot as a natural home. Existing: `ChatService`'s own authorization/scoping conventions should be reused, not reinvented.
- **Dependency:** Scope decision: read-only conversation viewing vs. the ability to intervene (send/moderate messages as admin).
- **Recommended action:** Decide the moderation-action scope, then build a conversation-list + thread-view screen, per-booking scoped, matching `ChatService`'s own IDOR guards. **Phase 13 evidence:** the real 1.8.10 deployment seeded a `view-order-chat` permission scoped per-order — real precedent for exactly this scoping shape.
- **Acceptance criteria:** Support staff can view a real conversation to investigate a dispute without a raw DB query; permission-gated and tested like every other admin screen (matching the §A pattern this backlog's own TECH work should follow).
- **Code changes required:** **Yes** — new screen.

### TECH-6 — Admin UI has no design system
- **Priority:** P1
- **Exact problem:** Only 7 one-off Blade components exist (confirmed this session: `address-map`, `catalog-tabs`, `icon`, `import-panel`, `setting-override-badge`, `yes-no`, `zone-map`) — no shared button/card/table/badge/modal/status-pill primitives. Every one of the 33 admin screens is styled independently.
- **Current implementation status:** Unchanged since the 2026-08-12 baseline audit; never in scope for any of this segment's 18 phases (all backend/API/data/RBAC work). This is the single largest genuinely-unstarted piece of work in the entire project.
- **Affected modules/files:** `resources/views/components/*`, all 33 admin screens' Blade views.
- **Dependency:** None technical — a genuine, multi-week engineering effort, not a decision.
- **Recommended action:** Per `FINAL_RELEASE_READINESS_AUDIT.md §15`, this can run **in parallel** with API-first mobile app development — it does not block or get blocked by the backend hardening this mission completed.
- **Acceptance criteria:** A real shared component library exists and at least a meaningful subset of the 33 screens are migrated onto it (this backlog does not attempt to define a completion bar for a multi-week UI rebuild — that itself needs scoping as its own project, not a single acceptance line).
- **Code changes required:** **Yes** — large.

### TECH-7 — `CampaignService` audience resolution not chunked
- **Status:** ✅ **COMPLETE** — commit `df4e186`. Both `send()` and `resendToFailedRecipients()` rewritten to `chunkById(200, ...)`, matching `KycReminderService::dispatchDue()`'s own established pattern. 3 new regression tests, including a query-count assertion (205 recipients → exactly 2 SELECTs against `users`, not 1), verified via `git stash` to genuinely fail without the fix.
- **Priority:** P2
- **Exact problem:** `CampaignService::send()` (line 47) and `::resendToFailedRecipients()` (line 125) — confirmed unchanged this session — both still call `->get()` to hydrate the entire resolved audience into memory before looping, rather than `chunkById()` like `KycReminderService::dispatchDue()` already does.
- **Current implementation status:** Low-to-medium severity, shrinking in urgency as-is: Phase 18 already fixed the more severe half of this same code path (`CampaignNotification` now implements `ShouldQueue`, so the loop itself is fast — just dispatching queue jobs, not blocking on delivery). Only the audience hydration remains unchunked.
- **Affected modules/files:** `app/Services/CampaignService.php` (`send()`, `resendToFailedRecipients()`).
- **Dependency:** None.
- **Recommended action:** Rewrite using `chunkById()` + an accumulated count instead of `$recipients->count()`, matching `KycReminderService`'s own established pattern.
- **Acceptance criteria:** A new test proving memory/query behavior no longer scales linearly with audience size beyond a chunk boundary (mirroring the query-count regression test style already used for the Phase 18 N+1 fix).
- **Code changes required:** **Yes** — small, low-risk.

### TECH-8 — Soft-deleted `Service` cover-image purge
- **Priority:** P2
- **Exact problem:** `Service` correctly keeps a soft-deleted row's cover image on disk (by design, so restoring brings the picture back). No purge/prune job exists anywhere that ever hard-deletes an old soft-deleted `Service` row, so no image is currently orphaned in practice.
- **Current implementation status:** Currently inert — no live consequence.
- **Affected modules/files:** `app/Models/Service.php`, wherever a future purge job would live.
- **Dependency:** A purge/retention job being built at all (not currently planned anywhere).
- **Recommended action:** No action until a purge job is proposed; when it is, add the image-cleanup step in the same pass.
- **Acceptance criteria:** N/A until triggered.
- **Code changes required:** Yes, but only whenever a purge job is built — a one-line addition at that point, not a redesign.

---

## 5. TESTING items

### TEST-1 — Browser-driven E2E test suite
- **Priority:** P2
- **Exact problem:** No Playwright/Dusk-style multi-actor journey test exists anywhere. Confirmed this session: no Dusk/Playwright config found in the repo.
- **Current implementation status:** Explicitly out of scope for every one of this mission's 20 phases — consistently stated, not silently dropped.
- **Affected modules/files:** N/A — would be new test infrastructure entirely.
- **Dependency:** A decision on tooling (Dusk vs. Playwright vs. something else) and, likely, a real staging environment to run against safely.
- **Recommended action:** Scope as its own initiative once a staging environment exists (see ENV-2/ENV-3 — related but not identical: E2E doesn't strictly require the same MySQL-QA-database constraint that blocks true load testing, since it can run against SQLite/a seeded environment).
- **Acceptance criteria:** At least the core booking lifecycle (customer books → provider accepts → worker completes → payment/commission) is covered end-to-end through real browser interaction.
- **Code changes required:** **Yes** — new test infrastructure, large.

### TEST-2 — QA web frontend
- **Priority:** P2
- **Exact problem:** No standalone QA frontend exists. The `qa:seed`/`qa:clean` Artisan data factory (built at baseline) remains backend tooling only.
- **Current implementation status:** Unchanged since baseline; never in scope for any of this segment's 18 phases.
- **Affected modules/files:** N/A — new frontend project.
- **Dependency:** None technical, but low priority relative to TECH-6 (admin design system) — both are frontend efforts competing for the same kind of attention.
- **Recommended action:** No urgency named anywhere in prior audits; revisit if a real QA/staff testing workflow need emerges.
- **Acceptance criteria:** N/A — no scope has ever been defined for this beyond "does not exist."
- **Code changes required:** **Yes** — large, entirely unscoped.

---

## 6. ENVIRONMENT / infrastructure-constrained items

### ENV-1 — No database backup tooling
- **Priority:** P1
- **Exact problem:** No `spatie/laravel-backup` (or equivalent) package is installed — confirmed this session via `composer.json` grep. No backup Artisan command, no admin screen.
- **Current implementation status:** Real operational-continuity risk, but building it hastily carries its own real risk: an in-app "download the full production database" feature is a serious exfiltration surface if permission scoping is ever misconfigured.
- **Affected modules/files:** Would be new — a backup service/command, possibly `spatie/laravel-backup`.
- **Dependency:** Whether backups should be in-app (with what access control/storage target/retention policy) or handled entirely at the hosting/infrastructure level (Hostinger VPS — does it already provide automated snapshots? `PROJECT_HANDOFF.md` mentions a weekly Hostinger VPS snapshot and a nightly local `mysqldump` already exist at the infra layer, outside this application's own knowledge — worth confirming those are still real and sufficient before building an in-app duplicate).
- **Recommended action:** Confirm the existing infra-layer backup situation (weekly VPS snapshot + nightly `mysqldump`, per `PROJECT_HANDOFF.md`) is still real and adequate before deciding whether an in-app feature is even needed.
- **Acceptance criteria:** A documented decision on in-app vs. infra-only, with a real retention/access-control policy if in-app is chosen.
- **Code changes required:** Yes, once decided.

### ENV-2 — True multi-process load/concurrency testing
- **Priority:** P2 (blocked, not a choice)
- **Exact problem:** SQLite (`:memory:`, single-writer by design) is the only isolated test database available. No separate MySQL QA database is provisionable in this hosting environment — confirmed at baseline via a direct `CREATE DATABASE` permission denial, unchanged and unre-tested this segment since nothing about the hosting environment changed.
- **Current implementation status:** Race-condition tests (dispatch acceptance, loyalty redemption) prove row-locking mechanisms' *outcome* correctly under sequential-but-overlapping execution — not genuine multi-process concurrent timing. This limitation is consistently documented in the tests' own docblocks, not hidden.
- **Affected modules/files:** Test infrastructure (`phpunit.xml`'s `DB_CONNECTION=sqlite`).
- **Dependency:** A real staging environment with a provisionable MySQL database, outside this hosting environment's current constraints.
- **Recommended action:** Revisit if/when a proper staging environment becomes available. Not actionable within the current environment regardless of priority assigned.
- **Acceptance criteria:** A real MySQL QA database exists and a genuine concurrent-load test proves the same row-locking guarantees under real multi-process timing.
- **Code changes required:** No — this is purely an infrastructure/environment blocker, not an engineering task.

### ENV-3 — Live performance profiling under real load
- **Priority:** P2 (blocked, same root cause as ENV-2)
- **Exact problem:** Phase 18's index/N+1 work was evidence-based (real query-usage grepping), not live-load-profiled. No mechanism exists in this environment to generate or measure real production-scale load.
- **Current implementation status:** Meaningfully better-evidenced than the 2026-08-12 baseline, not load-proven.
- **Affected modules/files:** N/A — infrastructure gap.
- **Dependency:** Same as ENV-2 — a real staging/load-test-capable environment.
- **Recommended action:** Revisit alongside ENV-2 once a staging environment exists.
- **Acceptance criteria:** A real load test (e.g., k6/Locust against a staging deploy) confirms the indexed queries behave as expected at realistic volume.
- **Code changes required:** No.

---

## 7. PRODUCTION deployment prerequisites

This is the cross-cutting gate — what must be true before real customer traffic is considered, synthesized from every section above rather than re-describing each item. Ordered by what actually blocks real usage vs. what's important but not launch-blocking.

### Hard blockers (real users cannot function without these)
1. **BD-8 (SMS/push provider)** — without this, no real customer can complete phone OTP login or booking OTP. This is not a "should," it's a "can't function" gap today.
2. **TECH-1 (Payouts row-level scope)** — a real, live cross-franchise financial-data exposure; must close before any franchise-scoped payout admin is trusted with real multi-franchise data. — **✅ RESOLVED, commit `cfb1fa6`.**

### Should-fix-before-real-signups (compliance/security posture)
3. **BD-17 (Terms & Conditions / Privacy Policy)** — most jurisdictions require this at signup; currently nothing exists to reference.
4. **BD-24 (Sanctum token expiration)** — currently tokens never expire and there's no way for a user to revoke a lost device's access; acceptable for internal/dev use, not for real customer accounts at scale.
5. **DOC-1 (documentation reconciliation, §0 above)** — a stale source-of-truth doc (`CURRENT_MASTER_CHECKPOINT.md`) risks a future session or reviewer making a decision based on wrong information (e.g., "is `APP_DEBUG` still a live risk?" — it is not, but the checkpoint doc still says it is). — **✅ RESOLVED, commit `dbe6f4e`.**

### Real but not launch-blocking (already safe today, worth closing before scale)
6. TECH-2 (currency symbol — **✅ RESOLVED, commit `4c1db7c`**), TECH-3 (UTC timestamps — **✅ RESOLVED for franchise-scoped screens, commit `ba4f72e`; 4 deferred timestamps still open**), TECH-4 (chat moderation — **✅ COMPLETE, Option A only (read-only viewer), commit `e431667`; Option B/moderation remains open, see item 15**), TECH-7 (audience chunking — **✅ RESOLVED, commit `df4e186`**), ENV-1 (backup policy), BD-16 (`partner.workers.assign`), BD-11 (`payment_methods` consolidation).

### Structural, non-blocking, can run in parallel with launch prep
7. TECH-6 (Admin UI design system) — per `FINAL_RELEASE_READINESS_AUDIT.md §15`'s own recommendation, this and API-first mobile app development are independent tracks; neither should gate the other.

### Explicitly NOT required before a real production decision (environment-blocked, no safe way to close sooner)
8. ENV-2/ENV-3 (load/concurrency testing) — blocked on infrastructure this hosting environment doesn't currently provide. TEST-1/TEST-2 (E2E, QA frontend) — real but unscoped, no urgency established by any prior audit.

### Explicitly already closed, do not re-open
9. Everything in §1 COMPLETE, including **Risk #25 (`APP_DEBUG`)** — verified RESOLVED via a live HTTP request, not just config inspection, as of commit `ed26e48`.

---

## Proposed execution order for Phase 21 onward

This is a sequencing proposal only — no work has started.

1. **DOC-1** — correct the C1–C5 documentation contradictions (near-zero risk, prevents future sessions from re-deriving already-known facts or acting on stale information). Should genuinely be step zero of any Phase 21 work. — **✅ COMPLETE, commit `dbe6f4e`.**
2. **TECH-1** (`Payouts\Manage` scope fix) — no business decision blocking it, small and mechanical, closes a real live data-exposure gap immediately. — **✅ COMPLETE, commit `cfb1fa6`.**
3. **BD-8** (SMS/push vendor decision + integration) — the one true hard launch-blocker; the decision itself (vendor choice) can start immediately in parallel with #1/#2 since it needs no code yet, but the integration work should be prioritized the moment a vendor is chosen given real historical precedent already narrows the choice.
4. **BD-17** (Terms & Conditions / Privacy Policy) — legal review can start in parallel with the above; low engineering cost once content exists (TECH-5-equivalent signup wiring is a small follow-on, not currently a separate tracked item since no signup flow exists yet to wire it into).
5. **BD-24** (Sanctum expiration policy) — decision + implementation, moderate size, real security-hygiene value before real accounts scale.
6. **TECH-2 + TECH-3 + TECH-7** (currency symbol, UTC timestamps, campaign chunking) — bundle as one "admin display/consistency hardening" pass, since they're all mechanical, low-risk, and share the same "needs new test coverage before touching" caution the register itself already names for TECH-2/TECH-3. — **TECH-2 ✅ COMPLETE (`4c1db7c`); TECH-3 ✅ COMPLETE for franchise-scoped screens (`ba4f72e`), 4 deferred timestamps still open; TECH-7 ✅ COMPLETE (`df4e186`).**
7. **TECH-4** (chat moderation screen) + **ENV-1** (backup policy decision) — can run in parallel with each other and with #6; neither blocks nor is blocked by anything else in this list. — **TECH-4 ✅ COMPLETE, Option A only (`e431667`); ENV-1 still open.**
8. **BD-16 / BD-11** — architecture decisions with no current live risk; resolve opportunistically, no urgency to force a timeline.
9. **TECH-6** (Admin UI design system) — begin whenever real design/frontend capacity is available; explicitly independent of every item above per the release audit's own recommendation. This is the largest single item in the whole backlog and should be scoped as its own multi-phase effort, not a single Phase 21 task.
10. **Remaining P2 business decisions (BD-1, 2, 3, 6, 7, 9, 12, 13, 14, 18, 20)** — resolve as product/business bandwidth allows; none carry current live risk, and BD-6 is itself a dependency for BD-13, so sequence BD-6 before BD-13 whenever both are picked up.
11. **TEST-1, TEST-2, ENV-2, ENV-3** — revisit only once a real staging environment with a provisionable MySQL database exists; not schedulable meaningfully before then regardless of desire.

**This document is now the source of truth for all remaining work.** Future sessions should update it in place (matching `KNOWN_RISKS_AND_DECISIONS.md`'s own established discipline) rather than re-deriving this backlog from scratch.
