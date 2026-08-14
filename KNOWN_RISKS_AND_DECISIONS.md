# Known Risks & Pending Business Decisions

**Persistent register, maintained across sessions.** Every unresolved item below is real, found by direct repository inspection, not guessed. Nothing here is silently resolved — a missing business decision stays missing until a human actually makes it. Update this file in place as items are resolved or new ones are found; never delete a resolved item without a note of how/when it was resolved.

Format per item: **Issue** · Current behavior · Risk · Why unresolved · Business decision required · Safe current default · Affected modules · Blocked?

---

## 1. Referral reward values

- **Issue:** `ReferralService::qualifyFromCompletedBooking()` reads `referral.reward_type`/`referral.reward_amount`/`referral.reward_points` via `Setting::get()` with hardcoded fallback defaults (`wallet` / `50` / `100`) baked in by a prior session.
- **Current behavior:** Every qualifying referral pays ₹50 (or 100 points) unless an admin overrides the `Setting` at some scope.
- **Risk:** Low direct risk (configurable, admin-overridable), but the *default* values were never a deliberate business decision — they were placeholder numbers from initial scaffolding.
- **Why unresolved:** Final commercial reward values are a business/marketing decision, not an engineering one.
- **Business decision required:** What should the real referral reward amount/points be, per country/franchise if it varies?
- **Safe current default:** Existing `Setting`-driven values stand; configurable, not hardcoded in code.
- **Affected modules:** Referral engine, Loyalty, Wallet.
- **Blocked:** No — engineering architecture is complete and correct; only the *number* is pending.

## 2. Cross-actor referral scope

- **Issue:** `ReferralService`/`Referral` model only support Customer↔Customer (`referrer_id`/`referred_id` both plain `users`, qualification = referred user's first completed booking *as a customer*).
- **Current behavior:** Partner↔Customer, Partner↔Worker, Worker↔Customer referral combinations have no qualification logic at all.
- **Risk:** None yet (feature simply doesn't exist for those combinations) — but building it requires deciding which actor-pair combinations are actually in scope for v1, and what "qualifying transaction" means for a non-customer referred party (e.g., a referred Provider's first *accepted job*, not booking).
- **Why unresolved:** Not yet built (Phase 3 of the current mission) — qualification semantics per actor pair are a product decision, not purely engineering.
- **Business decision required:** Which actor-pair combinations ship in v1; what counts as a "qualifying transaction" for a referred Partner/Worker.
- **Safe current default:** None invented; Customer↔Customer remains the only live path until this is decided.
- **Affected modules:** Referral engine.
- **Blocked:** Partially — the existing baseline (30 days active + 2–3 qualifying transactions) is documented and reusable; the per-actor-pair "what counts as qualifying" definition is not.

## 3. Anti-fraud signals for referrals

- **Issue:** No device/payment/address linkage detection, no velocity-anomaly detection, no duplicate-account detection exists anywhere in `ReferralService`.
- **Current behavior:** Only two checks exist: no self-referral (`referrer_id !== newUser->id`), and one referral per referred user (DB-unique-enforced).
- **Risk:** A referral reward engine without fraud signals is a genuine abuse vector once real reward money is involved (fake accounts farming rewards).
- **Why unresolved:** Real fraud-signal thresholds (what device-linkage pattern counts as suspicious, what velocity is "anomalous") are themselves judgment calls that need product/risk-team input, not invented by an engineering pass.
- **Business decision required:** Which fraud signals to check, and at what threshold; what happens on a flagged referral (auto-block vs. manual review queue).
- **Safe current default:** None — this is a real gap, not a policy choice with a safe default.
- **Affected modules:** Referral engine, Wallet (reward payout).
- **Blocked:** Yes, for anything beyond basic architecture (a review-queue *mechanism* is buildable without inventing the actual fraud rules).

## 4. Performance/Growth Campaign reward values & targets

- **Issue:** No real Performance/Growth Campaign engine exists (the existing `CampaignService`/`NotificationCampaign` is a notification-broadcast engine, unrelated).
- **Current behavior:** N/A — not built.
- **Risk:** N/A until built.
- **Why unresolved:** Target metrics (e.g., "10 bookings in 30 days"), ranking rules, and reward amounts are commercial decisions.
- **Business decision required:** Target metrics per actor type, ranking/tie-break rules, reward values, approval workflow.
- **Safe current default:** N/A — configurable architecture can be built without inventing these numbers (Phase 4 of the current mission).
- **Affected modules:** New Campaign/Incentive engine, Wallet.
- **Blocked:** Yes, for reward values/targets specifically; the configurable *shape* is buildable now.

## 5. Tips / Compensation rate structures

- **Issue:** No tip, waiting-compensation, rain-compensation, overtime, peak, or night-compensation model exists anywhere in the schema.
- **Current behavior:** N/A — not built.
- **Risk:** N/A until built.
- **Why unresolved:** Actual rates (₹/minute waiting, rain surcharge %, overtime multiplier, etc.) are commercial/labor decisions.
- **Business decision required:** Every rate above, plus which of these apply per country/franchise.
- **Safe current default:** N/A — configurable rule architecture can be built now (Phase 5), with all rates left at `Setting`-driven defaults of 0 (no effect) until set.
- **Affected modules:** New Compensation engine, Wallet, Worker/Provider payout.
- **Blocked:** Yes, for the actual rates; the ledger-safe architecture is buildable now.

## 6. Worker compensation model

- **Issue:** Carried forward from an earlier session's own Open Business Decisions list — no defined base-pay/commission model for Workers (distinct from Provider commission, which IS defined via `CommissionService`).
- **Current behavior:** Workers have no independent earnings model; they operate under a Provider's own commission split when delegated work.
- **Risk:** None currently (no code path assumes a worker compensation model exists).
- **Why unresolved:** Genuine, unresolved product decision from before this session.
- **Business decision required:** Full worker compensation model definition.
- **Safe current default:** N/A.
- **Affected modules:** Worker/Rider architecture, Wallet, Commission.
- **Blocked:** Yes, entirely — no safe partial architecture exists yet because the shape of the decision itself is undefined.

## 7. Coupon system's customer-facing launch decision

- **Issue:** Carried forward — `coupons`/`coupon_usages` schema and FK integrity exist (hardened in an earlier session), but whether/when Coupons ship as a real customer-facing feature was never decided.
- **Current behavior:** Coupon infrastructure exists at the DB level; no customer-facing redemption flow is wired up.
- **Risk:** None — dormant, not a live surface.
- **Why unresolved:** Launch timing/scope is a product decision.
- **Business decision required:** Whether/when to launch coupons customer-facing, and under what constraints (stacking with flash sales/badges, etc. — see item 12 below).
- **Safe current default:** Stays dormant.
- **Affected modules:** Coupons, Bookings, (new) Flash Sale engine if it integrates with coupons.
- **Blocked:** Yes, entirely product-gated.

## 8. Real SMS / push provider

- **Issue:** `LogSmsAdapter`/`LogPushAdapter` are the only bound implementations (`AppServiceProvider::register()`) — no real Twilio/MSG91/Fast2SMS/FCM integration exists.
- **Current behavior:** OTP codes and push payloads are written to the server log only, safe for dev/QA, **unsafe for real production traffic** (OTP codes would never reach a real customer's phone).
- **Risk:** High if this ships to real users unchanged — login/booking OTP would be undeliverable.
- **Why unresolved:** Vendor selection + real account credentials are a business/procurement decision, not an engineering one.
- **Business decision required:** Which SMS/push vendor(s), real account credentials.
- **Safe current default:** `LogSmsAdapter`/`LogPushAdapter`, explicitly documented as dev/QA-only everywhere they're referenced (Operations screen flags this too).
- **Affected modules:** OTP (login + booking), all transactional/marketing notifications.
- **Blocked:** Yes — architecture (the `SmsAdapter`/`PushAdapter` contracts) is already in place and ready for a real binding the moment credentials exist.

## 9. Second payment provider

- **Issue:** `PaymentGateway` contract exists (this session, commit `6e4c8e7`) but only `RazorpayService` is bound.
- **Current behavior:** Razorpay is the only usable gateway.
- **Risk:** None — single-provider was already the deliberate, decided state; not a gap.
- **Why unresolved:** A second provider requires a real vendor decision + real credentials, same as item 8.
- **Business decision required:** Whether/when a second provider is needed, which vendor.
- **Safe current default:** Razorpay only, real credentials in `.env`, never source.
- **Affected modules:** Payment.
- **Blocked:** Yes, entirely vendor/procurement-gated. Architecture is ready.

## 10. Commission clawback on refund

- **Issue:** `CancellationService::refundIfPaid()` refunds the customer (via gateway or wallet credit) but never touches the `commissions` table — a booking that was already `completed` (commission applied) and is later cancelled/refunded would leave the provider's commission earnings untouched.
- **Current behavior:** No commission reversal on refund, ever.
- **Risk:** Real, but the actual boundary matters: `AdminCancelBookingAction::execute()` explicitly refuses to cancel an already-`completed` booking (`throw new \RuntimeException` for `completed`/`cancelled` statuses) — so in practice, a refund can only happen on a PRE-completion cancellation, where commission was never applied in the first place (commission only applies via `CompleteBookingAction`). **This means the clawback scenario may not be currently reachable at all** — confirmed by reading both actions directly, not assumed. Flagged here because it's the kind of gap that becomes reachable the moment a new cancellation/dispute path is added (e.g., a post-completion dispute-driven refund), not because it's exploitable today.
- **Why unresolved:** If/when a post-completion refund path is ever built, whether to claw back commission (and from whom — provider, franchise, platform, or split) is a business policy decision.
- **Business decision required:** Commission clawback policy for any future post-completion refund/dispute path.
- **Safe current default:** No post-completion refund path exists, so no clawback is needed yet. Documented here so it isn't silently invented if that path is ever built.
- **Affected modules:** Commission, Payment, Wallet.
- **Blocked:** N/A today (unreachable path); blocks only a *future* feature.

## 11. `payment_methods` / `payment_accounts` admin UI

- **Issue:** Both tables exist and are used at the model level (`PaymentMethod`, `PaymentAccount`), but neither has an admin UI. `payment_methods` (razorpay/cash/wallet rows) appears to duplicate the *concept* already covered by the `payment.*_enabled` Settings toggles.
- **Current behavior:** No admin visibility or management of either table.
- **Risk:** Low — neither table is a live write target of any current flow beyond seeding (confirmed by inspection during the Payment Gateway slice).
- **Why unresolved:** Building an admin UI for `payment_methods` risks creating a second, competing "which payment methods are enabled" control alongside the existing `payment.*_enabled` Settings toggles — a genuine architecture-consolidation decision, not a UI gap.
- **Business decision required:** Should `payment_methods` be retired in favor of the existing Settings toggles, or does it serve a distinct purpose (e.g., per-franchise method availability) that the Settings toggles don't yet cover?
- **Safe current default:** Left as-is; not surfaced in the admin panel to avoid presenting two competing controls for the same concept.
- **Affected modules:** Payment, Settings.
- **Blocked:** Yes, on the consolidation decision above.

## 12. Flash Sale × Coupon × Badge stacking rules

- **Issue:** (Anticipatory — logged before Flash Sale engine is built.) Once a Flash Sale engine exists, how it interacts with Coupons (item 7) and Plan Engine discounts (`stacking_strategy`) needs a rule.
- **Current behavior:** N/A — not built yet.
- **Business decision required:** Can a flash-sale price stack with a coupon? With a Plan Engine member discount? Which wins if both apply?
- **Safe current default:** Default to `exclusive` (no stacking) unless/until decided, mirroring `Plan.stacking_strategy`'s own existing `exclusive` default.
- **Affected modules:** (future) Flash Sale engine, Coupons, Plan Engine.
- **Blocked:** No — safe default (no stacking) lets the engine ship without inventing a stacking policy.

---

*Last updated: 2026-08-14, start of the full-day autonomous session (baseline commit `6e4c8e7`).*
