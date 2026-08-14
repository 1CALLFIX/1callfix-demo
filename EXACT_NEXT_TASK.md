# Exact Next Task

**Current HEAD:** `53d6203` (all three commits below are on `main`, none pushed/deployed to production — production remains `ba0635a`, untouched)

## What was completed in the "FINAL AUTONOMOUS BACKEND + ADMIN COMPLETION MISSION" session (2026-08-14)

This is a genuine continuation, not a restart. Three independently verified, independently committed milestones:

1. **`ce46e3f`** — Fixed the real cause of the `BookingOtpDeliveryTest` failure reported at session start: `AcceptBookingAction`'s `BookingStatusNotification` send was the one customer notification NOT wrapped in the established try/catch-and-log pattern, so a real channel-adapter failure crashed the whole acceptance flow before either OTP send below it ever ran. Fixed by extracting `sendStatusNotification()`, mirroring the existing `sendOtpNotification()` pattern exactly. Also carried forward and verified the two Group A fixes from the prior session (scoped-admin login, Providers KYC authorization).
2. **`09061a2`** — Forensic sweep of every `hasPermission()` call site in `app/Livewire` found that mutating actions were universally authorized, but **viewing was not**: 12 `.view` permissions had been seeded across prior sessions (each with an explicit "this screen should check it" comment in its own migration) but none were ever wired up. Any actor who merely cleared `EnsureHasAdminAccess` could view full cross-franchise commission splits, the wallet ledger, loyalty/referral data, and every customer's PII. Fixed across 15 screens (Dashboard, Bookings, Providers, Workers, Customers, Commissions, WalletLedger, Loyalty, Subscriptions, Plans, NotificationCenter).
3. **`53d6203`** — Built the Operations/Troubleshoot admin screen the mission's own special instruction asked to specifically investigate. Confirmed genuinely absent (no failed-job visibility, no notification-failure visibility, no health indicators anywhere), despite the underlying data already existing (`failed_jobs`, `notification_logs`). New `/admin/operations`: failed-job list + retry/discard, last 50 notification delivery failures, and a system health panel (DB/queue/cache/mail driver, SMS/push provider — flags the dev-only Log adapters explicitly, storage writability, maintenance mode).

**Test suite progression this session:** 163/163 → 166/166 → 173/173 passed. Every milestone was run (full suite, not just its own filter) before committing. 0 failures, 0 errors, 0 warnings at every checkpoint. Nothing was committed until green.

## What was audited but NOT changed (real evidence gathered, documented honestly rather than guessed at)

- **Payment Gateway (Phase 12):** Razorpay integration is real and fairly mature — order creation, checkout-signature verification, webhook-signature verification, idempotent capture/failed handling (covers booking payments, wallet top-ups, and plan subscriptions), refund method. **Gap:** single-provider only — `RazorpayService` is a concrete class, not behind a shared gateway interface/contract, and there is no admin UI to configure gateway credentials (they come from `.env`/`config/services.php` only). Extracting a `PaymentGatewayInterface` + a Settings > Payment Gateway admin tab (secrets via Laravel's encrypted config, never source) is real, scoped, buildable work — not started this session (see Remaining Work below). Not blocked by a business decision — Razorpay as the vendor was already decided by a prior session.
- **Referral engine (Phase 5):** `ReferralService`/`Referral` model are real but Customer→Customer only (`referrer_id`/`referred_id` both plain `users`, qualification = referred user's first completed booking as a customer). No cross-actor (Partner↔Customer, Partner↔Worker, etc.) referral logic exists. No anti-fraud signals (device/payment/address linkage) exist. This is the real starting point for Phase 5 — not started this session.
- **Campaign engine:** `CampaignService`/`NotificationCampaign` exist but are a **notification broadcast** engine (compose → audience → send), not a performance/growth **incentive** engine with targets/progress/ranking/rewards. Phase 6 as described in the mission brief is genuinely unbuilt.
- **Badge engine:** `ProviderBadge` exists but is a bare pivot (`provider_id`, `badge` string, `awarded_at`) — not a configurable NEW/POPULAR/TRENDING catalog-badge engine with priority/styling/expiry/scope. Phase 7 as described is genuinely unbuilt.
- **Chat:** `ChatMessage` model exists (`booking_id`, `sender_id`, `receiver_id`, `message`, `attachment_url`, `read_at`) but has zero controller/service/authorization/route anywhere — a dormant, unused model. Phase 9 is genuinely unbuilt.
- **Tips/compensation:** No tip, waiting-compensation, rain-compensation, or peak/night-compensation model or logic exists anywhere. Phase 8 is genuinely unbuilt.
- **Printing:** Confirmed absent (0 matches for print/invoice/PDF anywhere in `app/`), matching `PROJECT_CURRENT_STATE.md`'s own prior finding.

## What remains (honest categorization, not invented)

**Category 1 — safely buildable now, no business decision needed:**
- Payment Gateway abstraction (`PaymentGatewayInterface` + admin config tab) — vendor already chosen, just needs the generalization work.
- Badge engine (config-driven labels, no money involved).
- Per-row scope filtering for the 15 screens fixed in `09061a2` (currently gated on "holds the `.view` permission anywhere", not filtered to the viewer's own franchise/zone/country — a real, separate, larger enhancement, explicitly flagged in that commit's own message).
- API/security hardening sweep (Phase 21) — no dedicated pass done this session beyond the RBAC view-gap closure.

**Category 2 — implemented and working, but incomplete for full mission scope:**
- Referral engine (Customer↔Customer only, no cross-actor, no anti-fraud).
- Payment Gateway (single-provider, no abstraction).

**Category 3 — business decision required before implementation (do not invent):**
- Final referral reward amounts/points beyond the existing `Setting`-driven defaults.
- Campaign/incentive reward values, targets, ranking rules for a real performance-campaign engine.
- Tip/compensation rate structures.
- Worker compensation model (carried forward from the prior session's own Open Business Decisions list).
- Coupon system's real customer-facing launch decision (carried forward, unchanged).

**Category 4 — blocked by external dependency:**
- Real SMS/push provider configuration (still `LogSmsAdapter`/`LogPushAdapter`, safe for dev/QA, unsafe for production — carried forward, unchanged).
- A real second payment provider, if one is ever chosen, needs real credentials.

**Category 5 — not started, large independent programs (unchanged from before this session):** Chat, Tips/Compensation, Badge engine (real one, not the KYC pivot), Campaign/Performance-Incentive engine (real one, not the notification broadcaster), Printing, Admin UI design system, Glover/6amMart full parity audit, multi-country/i18n audit, subscriptions/commercial engine audit, catalog/POS audit.

## Exact next action

Pick ONE of Category 1's items and build it as its own coherent, tested, committed slice — same discipline as this session's three milestones (forensic-check the existing code first, never assume from docs, test before committing, one coherent milestone per commit). Recommended order given what's already been touched this session: (1) per-row scope filtering for the 15 `.view`-gated screens (closes the loop on this session's own biggest fix), (2) Payment Gateway abstraction, (3) Badge engine.
