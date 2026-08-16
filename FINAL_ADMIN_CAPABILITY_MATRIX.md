# 1CallFix — Final Admin Capability Matrix

**Mission Phase 19.** This is the definitive, verified inventory of the admin panel as it exists in the repository today — every registered route cross-checked against its actual `mount()` gate, its sidebar entry, and its test coverage by direct inspection this session, not copied from an earlier document's notes. Where this conflicts with `PROJECT_CURRENT_STATE.md`'s older `§12 Admin Panel` section (which lists 21 screens and predates 12 of this segment's phases), **this document wins** for admin-panel completeness; `CURRENT_MASTER_CHECKPOINT.md` remains authoritative for the phase-by-phase narrative of *how* each capability was built.

Labels: **IMPLEMENTED** shipped and wired · **VERIFIED** confirmed by an automated test this session or a prior one still passing · **PARTIAL** exists but incomplete · **MISSING** does not exist · **BUSINESS DECISION** needs a human call, not an engineering one · **UNREACHABLE** code that would be dead given current guards elsewhere.

---

## A. Admin screen inventory — every registered route, verified

Ground truth is `routes/admin.php` itself: 33 real Livewire screens/views (28 top-level, nav-reachable screens + 5 detail/drill-down views reached from a parent screen — `Bookings\Show`, `Providers\Show`, `Customers\Show`, `Workers\Show`, `FranchisePricing\Manage` — intentionally not standalone nav items) + 4 controller-based document/video download endpoints + login/logout. For every one of the 33 Livewire screens/views, this session directly verified three things that a prior session's notes had only asserted piecemeal:

1. **The screen's own `mount()` aborts 403 without the stated permission** (`abort_unless(...hasPermission[Anywhere](...))`) — read the actual source, not grepped for its presence.
2. **For the 28 top-level screens, the sidebar (`resources/views/layouts/admin.blade.php`)'s `$navItems` entry uses the exact same permission slug(s)** the screen's own `mount()` checks — the sidebar is documented in its own comment as "kept in sync by hand since no shared route→permission registry exists," so this was a real drift risk, not a formality. Zero mismatches found across all 28.
3. **A real automated test exercises both the denial (no permission → 403) and success (holding the permission → 200) paths** — either via the shared table-driven `tests/Feature/Rbac/ScreenViewAuthorizationTest.php` (27 of the 33 screens/views) or a dedicated permission-denial assertion in that screen's own feature test file (the 6 screens added after that shared test file was last extended: Badges, Flash Sales, Performance Campaigns, KYC Support Requests, Operations, Payments).

**Result: 33/33 admin screens/views have a real view-level permission gate and real automated test coverage for both the denial and success paths; all 28 top-level screens additionally have a correctly-synced sidebar entry. Zero gaps found.** This is itself the headline finding of this phase — not a new fix, but the first time this was verified end-to-end in one pass rather than assembled from 8 different phases' own partial audits (Phase 11 covered 12 originally-ungated screens + 15 from an earlier session; this phase re-verified those 27 and additionally verified the 6 screens Phase 11 predates).

| Screen | Route | Permission | Scope model | Test file |
|---|---|---|---|---|
| Dashboard | `admin.dashboard` | `dashboard.view` | any-scope (`hasPermissionAnywhere`) | `ScreenViewAuthorizationTest` |
| Bookings\Index | `admin.bookings.index` | `bookings.view` | any-scope | `ScreenViewAuthorizationTest` |
| Bookings\Show | `admin.bookings.show` | `bookings.view` | any-scope | `ScreenViewAuthorizationTest` |
| Providers\Index | `admin.providers.index` | `providers.view` | any-scope | `ScreenViewAuthorizationTest` |
| Providers\Show | `admin.providers.show` | `providers.view` | any-scope | `ScreenViewAuthorizationTest` |
| Customers\Index | `admin.customers.index` | `customers.view` | any-scope | `ScreenViewAuthorizationTest` |
| Customers\Show | `admin.customers.show` | `customers.view` | any-scope | `ScreenViewAuthorizationTest` |
| Workers\Index | `admin.workers.index` | `workers.view` | any-scope | `ScreenViewAuthorizationTest` |
| Workers\Show | `admin.workers.show` | `workers.view` | any-scope | `ScreenViewAuthorizationTest` |
| Geography\Manage | `admin.geography.index` | `geography.manage` | any-scope | `ScreenViewAuthorizationTest` |
| Franchises\Manage | `admin.franchises.index` | `franchises.manage` | any-scope | `ScreenViewAuthorizationTest` |
| FranchisePricing\Manage | `admin.franchises.pricing` | `franchise_pricing.manage` | **franchise-scoped** (the specific franchise in the URL) | `ScreenViewAuthorizationTest` |
| Zones\Manage | `admin.zones.index` | `zones.manage` | any-scope | `ScreenViewAuthorizationTest` |
| Categories\Manage | `admin.categories.index` | `categories.manage` | any-scope (global-only data, no franchise column) | `ScreenViewAuthorizationTest` |
| Subcategories\Manage | `admin.subcategories.index` | `categories.manage` (shared slug — seeded label covers both) | any-scope | `ScreenViewAuthorizationTest` |
| Services\Manage | `admin.services.index` | `services.manage` | any-scope | `ScreenViewAuthorizationTest` |
| Banners\Manage | `admin.banners.index` | `banners.manage` | any-scope | `ScreenViewAuthorizationTest` |
| Badges\Manage | `admin.badges.index` | `badges.view` | any-scope | `BadgeEngineTest` |
| FlashSales\Manage | `admin.flash-sales.index` | `flash_sales.view` | any-scope | `FlashSaleEngineTest` |
| PerformanceCampaigns\Manage | `admin.performance-campaigns.index` | `performance_campaigns.view` | any-scope | `PerformanceCampaignEngineTest` |
| Kyc\SupportRequests | `admin.kyc.support-requests.index` | `kyc.support_requests.create` OR `kyc.support_requests.decide` | any-scope, either-permission | `KycEngineTest` |
| Settings\Manage | `admin.settings.index` | `settings.manage` | any-scope (screen-wide gate now covers all 10 tabs — 3 methods keep their own redundant check as defense-in-depth) | `ScreenViewAuthorizationTest` |
| Cms\Manage | `admin.cms.index` | `cms.manage` | any-scope | `ScreenViewAuthorizationTest` |
| Roles\Manage | `admin.roles.index` | `roles.manage` | any-scope | `ScreenViewAuthorizationTest` |
| Payouts\Manage | `admin.payouts.index` | `payouts.manage` | any-scope at screen entry; **row-level franchise scope applied to the query itself** since Phase 21 item TECH-1 (2026-08-16, commit `cfb1fa6`) — risk register item 22 resolved | `ScreenViewAuthorizationTest`, `RowLevelScopeAuthorizationTest` |
| WalletLedger\Index | `admin.wallet-ledger.index` | `wallets.view` | any-scope | `ScreenViewAuthorizationTest` |
| Loyalty\Index | `admin.loyalty.index` | `loyalty.view` | any-scope | `ScreenViewAuthorizationTest` |
| Commissions\Index | `admin.commissions.index` | `commissions.view` | any-scope | `ScreenViewAuthorizationTest` |
| Payments\Index | `admin.payments.index` | `payments.view` | any-scope | `PaymentsScopeAuthorizationTest` |
| NotificationCenter\Manage | `admin.notifications.index` | `notification.view` | any-scope | `ScreenViewAuthorizationTest` |
| Plans\Manage | `admin.plans.index` | `plans.view` | any-scope | `ScreenViewAuthorizationTest` |
| Subscriptions\Index | `admin.subscriptions.index` | `subscriptions.view` | any-scope | `ScreenViewAuthorizationTest` |
| Operations\Health | `admin.operations.index` | `operations.view` | any-scope (individual detection checks are row-scoped via `AuthorizationService::scopeQuery()`) | `OperationsHealthTest` |

A view-level `hasPermissionAnywhere()` gate is a screen-entry check, not row-level filtering — most screens listed "any-scope" additionally row-scope their actual query results via `AuthorizationService::scopeQuery()` (verified per-screen across Phases 10, 11, 14, 15). `Payouts\Manage` was the one confirmed exception at the time this document was written (Phase 19) — closed since, 2026-08-16, Phase 21 item TECH-1, commit `cfb1fa6` (risk register item 22).

**Post-Phase-19 addition (Phase 21 item TECH-4, 2026-08-16, commit `e431667`) — not part of the 33-screen count verified above:** a new `Chat\Manage` screen (`admin.chat.index`, `/admin/chat`) was added, gated on a new `chat.view` permission (any-scope at entry, row-scoped by franchise/zone via `AuthorizationService::scopeQuery()` on the underlying booking, same as every screen in the table above), with its own dedicated test file `AdminChatViewerTest` (19 tests) rather than the shared `ScreenViewAuthorizationTest`. This is a genuinely new screen built after this document's own audit, not a screen this document failed to find — recorded here rather than silently inflating the original count. See §B "Chat" row below and `KNOWN_RISKS_AND_DECISIONS.md` item 15 for what it does and, just as importantly, does not do (no moderation/intervention capability).

## B. Full capability status — consolidated across all 18 completed mission phases

This mission's own phase-by-phase capability tables (`CURRENT_MASTER_CHECKPOINT.md §3`) already carry the detailed evidence for each row below; this section is the flattened, single-scan summary. Grouped by domain rather than by phase number, since a domain (e.g. "KYC") was frequently touched across 3+ phases.

| Domain | Capability | Status |
|---|---|---|
| **Booking core** | Booking FSM, dispatch, worker delegation, OTP-gated start/complete | [IMPLEMENTED] [VERIFIED] — pre-segment baseline, untouched this segment except Phase 18's index/N+1 fixes |
| **Wallet / Loyalty** | Wallet ledger, top-ups, loyalty earn/redeem | [IMPLEMENTED] [VERIFIED] — Phase 15 fixed a real redemption race (row-locking); Phase 18 fixed reconciliation's own N+1 |
| **Commission / Payouts** | Commission split, payout request/approval, payment accounts | [IMPLEMENTED] [VERIFIED] — `payment_accounts` write path added Phase 9; `Payouts\Manage` row-level scope gap resolved Phase 21 item TECH-1, commit `cfb1fa6` (item 22) |
| **Performance/Growth Campaigns** | Configurable audience/scope/metric/reward campaign engine | [IMPLEMENTED] [VERIFIED] — Phase 1, 34 tests |
| **KYC** | Document security, configurable requirements, verification video, 30-day deadline + reminders, withdrawal restriction, franchise support-request workflow | [IMPLEMENTED] [VERIFIED] — Phases 2–4, 11 (Settings UI), 18 (reminder queueing fix) |
| **Compensation** | Tips, overtime/night/peak (auto), rain/waiting (admin-triggered) | [IMPLEMENTED] [VERIFIED] — Phase 5; Tips had zero API callers until Phase 11 |
| **Chat** | Universal Chat (Customer↔Partner/Worker) | [IMPLEMENTED] [VERIFIED] — Phase 6 (customer/partner-facing API); read-only admin viewer added Phase 21 item TECH-4, commit `e431667` (`chat.view`, row-scoped, activity-logged — see §A's post-Phase-19 addition note above). **No moderation/intervention capability exists** — that remains an open business decision, risk register item 15 |
| **Documents** | Invoice/receipt PDFs, idempotent numbering | [IMPLEMENTED] [VERIFIED] — Phase 7 |
| **Notification Center** | Templates CRUD, delivery logs, provider status, retry, in-app read API, campaign broadcast | [IMPLEMENTED] [VERIFIED] — Phase 8; Phase 18 fixed bulk-campaign-send blocking |
| **Operations / Troubleshoot** | Webhook receipt logging + reprocess, scheduler run-history, reconciliation/dispatch-health/stuck-booking detection, activity log | [IMPLEMENTED] [VERIFIED] — Phase 10; Phase 15 extended reconciliation; Phase 18 fixed a real N+1 in it |
| **Admin RBAC surface** | View-level permission gate on every screen, sidebar filtered per-role, Settings enforced screen-wide | [IMPLEMENTED] [VERIFIED] — Phase 11; **re-verified end-to-end this phase, see §A, zero drift found** |
| **Settings** | 27 previously-unexposed Setting keys given real admin UI (KYC/Compensation/Security-OTP/Operations/Subscriptions tabs) | [IMPLEMENTED] [VERIFIED] — Phase 11 |
| **CMS / Content** | Public read API for pages/FAQs/banners, draft/published toggle | [IMPLEMENTED] [VERIFIED] — Phase 12; T&Cs/Privacy content still a real business decision (item 17); multi-language still architecture-wide-undecided (item 18) |
| **Reviews** | Customer submit + provider reply, `rating_avg` now backed by real data | [IMPLEMENTED] [VERIFIED] — Phase 13 |
| **Master Catalog Import** | External_id-based, idempotent, transaction-safe, audited Category/Subcategory/Service import | [IMPLEMENTED] [VERIFIED] — Phase 14; Vendors/Menus/Products import and DB backup deliberately not built (items 20, 21) |
| **Data Export** | Commissions/Payouts export, sensitive banking fields masked | [IMPLEMENTED] [VERIFIED] — Phase 14 |
| **Financial reconciliation** | Wallet-topup-without-credit and negative-loyalty-balance detection | [IMPLEMENTED] [VERIFIED] — Phase 15; Phase 18 fixed the underlying detection query's own N+1 |
| **API layer** | Rate limiting (60/min per user), IDOR audit across 17 controllers/40+ endpoints, HTTP-level test coverage for 9 previously-untested routes | [IMPLEMENTED] [VERIFIED] — Phase 16; Sanctum token expiration still a real product decision (item 24) |
| **International readiness** | Phone validation (already country-agnostic); currency-symbol and timezone-display consistency | [PARTIAL] — audited Phase 17, two real gaps logged (items 26, 27), neither fixed yet |
| **Performance / scale** | Hot-column indexes (9 across 8 tables), N+1 fix, bulk-notification queueing | [IMPLEMENTED] [VERIFIED] — Phase 18; audience-chunking in `CampaignService` still open (item 28) |
| **Admin RBAC + sidebar + screen inventory** | Fully verified end-to-end (see §A) | [VERIFIED] — Phase 19 (this phase) |

## C. Risk register summary — 28 items, `KNOWN_RISKS_AND_DECISIONS.md` is authoritative

Grouped by disposition rather than repeated in full here (see that file for the complete Issue/Risk/Business-decision-required writeup per item):

**Genuine business decisions, not engineering gaps (need a human call):** 1 (referral reward values), 2 (cross-actor referral scope), 3 (anti-fraud signals), 4 (campaign reward values/targets), 5 (tip/compensation rates), 6 (worker compensation model), 7 (coupon launch), 9 (second payment provider), 10 (commission clawback on refund), 11 (`payment_methods` consolidation), 12 (Flash Sale × Coupon × Badge stacking), 13 (30-day KYC deadline scope for Riders/Workers), 14 (per-country KYC docs), 17 (T&Cs/Privacy content — legal review), 18 (multi-language — architecture-wide), 20 (Vendors/Menus/Products import — no target schema), 21 (DB backup — infra/ops decision), 24 (Sanctum token expiration — mobile session lifetime).

**Real, closeable engineering follow-ups, as of this document's own writing (Phase 19):** 15 (no chat moderation screen), 16 (`partner.workers.assign` missing permission check — deliberately deferred pending an unbuilt Partner authorization layer), 19 (soft-deleted Service cover-image orphans), 22 (`Payouts\Manage` row-level scope), 26 (hardcoded ₹ across ~16 views), 27 (admin panel UTC-only display), 28 (`CampaignService` audience not chunked).

**Post-Phase-19 update, 2026-08-16 (Phase 21 items TECH-1/TECH-2/TECH-3/TECH-4):** three of the above are now resolved with a real fix and real verification trail, the same standard item 25 was held to: 22 (commit `cfb1fa6`), 26 (commit `4c1db7c`), 27 (commit `ba4f72e` — resolved for the franchise-scoped operational screens it names; 4 additional scope-shaped timestamps found during that pass remain open, no policy decided). Item 15's bounded half (read-only viewing) is also resolved (commit `e431667`); what's left of it is no longer a bounded engineering follow-up but a genuine business decision (whether admin intervention/moderation is wanted at all), so it now sits with this section's business-decision items rather than here. 16, 19, and 28 are unchanged, still open.

**Confirmed unreachable given current code (no action needed unless a future change reopens the path):** 23 (`referrals`/`performance_campaign_participants` cascade-delete on booking).

**Resolved (verified after this document was originally written, then fixed the same day under separate explicit authorizations for the check and the fix):** 25 (production `APP_DEBUG` — first verified CONFIRMED ACTIVE via read-only production SSH, then remediated: `.env` changed `true`→`false`, queue workers restarted via `php artisan queue:restart`, fix confirmed via `php artisan about` AND a live HTTP request showing no debug fields on a real 404 response. Was the single highest-severity item in the register; now the register's first resolved item — see `KNOWN_RISKS_AND_DECISIONS.md` item 25 for the complete trail).

**Historical vendor-selection evidence (not open questions, precedent already found):** 8 (SMS/push — Firebase+MSG91 confirmed as real prior choice).

## D. Test suite / quality summary

**620/620 passing, 1480 assertions, 0 failures/errors/warnings** as of this phase (`php artisan test`). Every commit this segment (18 phases, ~40 commits) was preceded by a full green run — no phase was ever committed on a red or skipped suite. Coverage highlights verified this phase specifically: every one of the 28 top-level admin screens has both a permission-denial and permission-success test (§A); the API layer has HTTP-level coverage for all 24 business-logic routes as of Phase 16; `ReconciliationService` has coverage for all 5 of its detection checks (not just the 2 most recently added) as of Phase 15.

**Still not covered, unchanged from `PROJECT_CURRENT_STATE.md §19`/§20's own long-standing note:** browser-driven E2E (Playwright/Dusk-style multi-actor journeys) — explicitly out of scope for every phase mentioning it, not silently dropped; the admin UI has no shared design system (§12 of that same document); production data volume remains near-zero, so no claim in this document or any prior one should be read as "battle-tested at real scale," only as "verified against the schema, RBAC model, and business logic being internally consistent."

## E. What this document does NOT claim

- **Not a production deployment readiness verdict** — that is Phase 20's own explicit charter (final release-readiness audit), not started as of this document.
- **Not a claim that every possible admin capability exists** — §B's `[PARTIAL]`/open risk-register rows are the honest list of what's real but incomplete; nothing here papers over a gap the register already tracks.
- **Not a re-verification of pre-segment (Phases 1–6, and everything before this mission's own phase-numbering began) business logic correctness** — those are `PROJECT_CURRENT_STATE.md`'s own domain, unchanged and untouched by this phase beyond the admin-screen inventory in §A, which does span the whole app regardless of which session originally built each screen.

---

*Written mission Phase 19 (2026-08-15). Supersedes `PROJECT_CURRENT_STATE.md §12` for admin-screen completeness; does not supersede it for anything else.*
