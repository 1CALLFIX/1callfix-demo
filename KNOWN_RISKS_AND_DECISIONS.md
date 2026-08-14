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
- **Current behavior:** Self-referral is blocked, one referral per referred user is DB-unique-enforced, and (as of the full-day mission session, Phase 3) an admin can now **manually** flag any referral as fraud — `ReferralService::flagAsFraud()`, `Loyalty\Index`'s Referrals tab — which claws back the wallet credit via `WalletService::debit()` if it was already rewarded (gracefully recording, not crashing on, an already-spent balance). This is a manual REVIEW mechanism, not automatic DETECTION.
- **Risk:** Still real — nothing flags a suspicious referral proactively; an admin has to already suspect one to act on it.
- **Why unresolved:** Real fraud-signal thresholds (what device-linkage pattern counts as suspicious, what velocity is "anomalous") are themselves judgment calls that need product/risk-team input, not invented by an engineering pass.
- **Business decision required:** Which automatic fraud signals to check, and at what threshold; whether a flagged referral should ever auto-block vs. always require manual review (manual review is what exists today).
- **Safe current default:** Manual flagging only, no automatic detection, no invented thresholds.
- **Affected modules:** Referral engine, Wallet.
- **Blocked:** Partially — manual review tooling is done; automatic detection is blocked on the threshold decision above.
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
- **Update (full-day mission Phase 3):** The identical unreachable-path finding applies to referral reward clawback-on-booking-cancellation — a referral only qualifies off a `completed` booking (`ReferralService::qualifyFromCompletedBooking()`), and a `completed` booking can't be cancelled (same `AdminCancelBookingAction` guard). Referral clawback WAS built this session, but only as an admin-driven manual fraud-flag action (`ReferralService::flagAsFraud()`, reachable anytime, not gated on a booking-cancellation path) — not as an automatic reaction to a cancellation that can't currently happen. If a post-completion refund/dispute path is ever built, both this item's commission question AND a parallel "does a legitimate (non-fraud) refund also reverse the referral reward it indirectly funded" question should be resolved together.

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

## 13. Does the 30-day KYC deadline / withdrawal restriction apply to Riders/Workers?

- **Issue:** The EOD mission brief's own 30-day-deadline/withdrawal-restriction text names "Partner" specifically, throughout, and never once says Rider/Worker for that particular policy (Phase 2's document/video requirements ARE written for both actors). `kyc_deadline_at`/`kyc_reminder_stage`/`kyc_video_status` were added to `providers` only; `field_workers` got only the widened `kyc_status` enum.
- **Current behavior:** A FieldWorker can have an indefinitely-incomplete KYC with no deadline, no reminders, and (there being no FieldWorker payout path in `PayoutService` at all today — only `provider`/`franchise_owner`) no withdrawal-restriction enforcement point to even attach to.
- **Risk:** Low today (FieldWorkers aren't paid out directly through `PayoutService` at all yet — they earn via `PartnerWorker`/Provider delegation, per Phase B0.1/B0.2's own architecture), but real the moment a direct FieldWorker payout path is ever built.
- **Why unresolved:** Extending the identical policy to Workers is a real, undecided product question (should worker withdrawal follow the same 30-day rule, a different one, or none at all), not an engineering gap.
- **Business decision required:** Whether/how the 30-day deadline and withdrawal restriction apply to Riders/Workers.
- **Safe current default:** No restriction applied to Workers; the architecture (`KycWithdrawalPolicyService`, `kyc_withdrawal_exceptions`) is directly reusable for FieldWorker the moment this is decided and a Worker payout path exists.
- **Affected modules:** KYC engine, Worker/Rider architecture, Payouts (once built).
- **Blocked:** Yes, on the policy decision; not blocked by anything structural.

## 14. Per-country KYC document requirements

- **Issue:** `kyc_document_requirements` seeded a single GLOBAL (country_id = null) default set — id_proof/address_proof/bank_details required, business_proof/tax_document/skill_certificate/police_verification/driving_licence/vehicle_document not required — built from the document TYPE NAMES the pre-existing schema comments already used, not invented, but the required/not-required split and any real per-country variation are both admin judgment calls.
- **Current behavior:** Every country currently gets the identical global default until an admin adds a country-specific override row.
- **Risk:** Low — admin-correctable per-country at any time via the `kyc_document_requirements` table (no admin UI for editing it yet — read-only via `KycDocumentService::requirementsFor()` today; only a direct DB/tinker edit or a future settings-screen addition can change it).
- **Why unresolved:** Real per-country compliance requirements (e.g. is a police verification legally mandatory in country X) need local legal/compliance input this session can't supply.
- **Business decision required:** Confirm/adjust the required-document list per country as real compliance requirements become known.
- **Safe current default:** The seeded global set stands; structurally ready for per-country overrides.
- **Affected modules:** KYC engine.
- **Blocked:** No — safe default in place; refinement only.

## 15. No admin chat-moderation screen exists

- **Issue:** Universal Chat (`ChatService`/`ChatController`, mission Phase 6) is a real, fully-wired Customer↔Partner/Customer↔Worker/Partner↔Worker messaging API — but it has zero admin-facing surface. No `admin/chat*` route or `App\Livewire\Chat\*` component exists anywhere. Phase 11's audit found it incorrectly listed under `CURRENT_MASTER_CHECKPOINT.md`'s "admin capability snapshot" table, which reads as an admin capability when it isn't one — corrected in that doc, but the underlying gap (support staff have no way to view a conversation to investigate a dispute) is real and unresolved.
- **Current behavior:** Conversations are fully functional between real actors but invisible to admin/support — the only escape hatch is a raw DB query.
- **Risk:** Low today (no live disputes reported since this is dev-only), but a genuine support/moderation gap once real users are on the platform.
- **Why unresolved:** A real admin chat-moderation screen (conversation list, thread view, likely per-booking scoping and IDOR guards matching `ChatService`'s own authorization) is a new, non-trivial feature — out of scope for a menu/settings *audit* to build silently.
- **Business/scope decision required:** Whether and when to build an admin chat-moderation screen, and what moderation actions (if any — read-only viewing vs. the ability to intervene) it should support.
- **Safe current default:** Not built; not claimed complete anywhere in the docs after this correction.
- **Affected modules:** Universal Chat, Operations/Troubleshoot (a natural home for it once built).
- **Blocked:** Yes, on a scope/priority decision — not blocked by anything structural.

## 16. `partner.workers.assign` has no permission check in `PartnerWorkerController`

- **Issue:** Phase 11's audit found `partner.workers.assign` seeded (`2026_08_11_044000_seed_worker_foundation_permissions.php`) with a comment explaining it and 5 sibling `worker.*`/`partner.workers.manage` permissions exist "so the next implementation slice has an authorization layer to check against, not because anything enforces them yet." `PartnerWorkerController::assignBooking()` (the real, working endpoint this permission was clearly meant for) performs zero `hasPermission()` check — it relies entirely on `$request->user()->providerProfile` (only a provider account can call it) plus `AssignBookingToWorkerAction`'s own ownership checks (the booking and worker must belong to that same partner).
- **Why NOT fixed in Phase 11:** The 5 sibling permissions' own seeding comment makes clear these are for a Partner/Worker-facing authorization layer *distinct from* the admin-panel RBAC `RoleAssignment` system (built for admin staff — super_admin/country_admin/.../support — not for Provider-role mobile-app accounts). No real Provider account has ever been granted an RBAC role assignment, and no such Partner-authorization-layer design exists yet. Adding a `hasPermission('partner.workers.assign')` check today would either (a) silently lock out every real provider from a working feature, or (b) require inventing a grant-everyone-by-default policy — both are business/architecture decisions this audit should surface, not invent.
- **Current behavior:** The endpoint is safe from cross-partner IDOR (ownership is checked), just not gated by the seeded permission concept.
- **Risk:** Low — the real authorization boundary (ownership) is already enforced; what's missing is the permission-based layer the migration anticipated, not a live security hole.
- **Business decision required:** Whether Partner/Worker-facing actions should ever go through the admin RBAC system, or need their own, separate authorization model — then wire `partner.workers.assign` (and the other 5 seeded-but-unused `worker.*` permissions) accordingly.
- **Safe current default:** Ownership-based authorization stays as the enforcement boundary until that decision is made.
- **Affected modules:** Partner/Worker delegation (Phase B0.2), future Worker self-service mobile app.
- **Blocked:** Yes, on the authorization-model decision.

## 17. No Terms & Conditions / Privacy Policy content exists

- **Issue:** Phase 12's audit confirmed `Cms\Manage` (Pages/FAQs, `content_pages`/`faqs`) is a fully working admin CRUD screen, and Phase 12 gave it a real public read path (`GET /api/pages/{slug}`, `GET /api/faqs`) — but no seeder, migration, or admin action has ever created a Terms & Conditions or Privacy Policy row. A fresh install has zero pages. No signup/login flow anywhere in the codebase references an "I agree to Terms" checkbox or link, and the one public web route (`GET /`) still renders the stock, unmodified Laravel `welcome.blade.php` scaffold.
- **Current behavior:** The admin screen and the new public API are both ready to serve this content the moment an admin creates it — but nothing has, and nothing links to it yet.
- **Risk:** Low today (dev-only, no real users/signups), but a genuine compliance gap the moment real user registration goes live — most jurisdictions require Terms/Privacy to be presented at signup.
- **Why unresolved:** Writing real Terms & Conditions / Privacy Policy text is a legal/business decision (jurisdiction-specific, needs actual legal review), not an engineering gap — inventing placeholder legal text here would be worse than leaving it honestly empty, since a fabricated legal document could be mistaken for a real one.
- **Business decision required:** Legal/compliance sign-off on actual Terms & Conditions / Privacy Policy text, then create the pages via the existing admin screen and wire a reference into the signup flow once one exists.
- **Safe current default:** Left empty; the admin CRUD + public API are ready the moment real content exists.
- **Affected modules:** CMS (Pages), future Customer/Partner signup flow.
- **Blocked:** Yes, on legal content and a signup flow to reference it from — neither exists yet.

## 18. Content layer is single-locale only; `users.preferred_language` is dead schema

- **Issue:** Phase 12's audit found no locale/language column anywhere in the content layer — `content_pages`, `faqs`, `service_categories`, `service_subcategories`, `services`, `banners` all store exactly one language's text per row, no translation table. Separately, `users.preferred_language` (seeded default `'en'` since the original users migration) is never read anywhere in `app/` — confirmed by grep, zero consumers. The Settings "Locale" tab (`locale.currency_symbol`) is currency-display-only, explicitly documented as such in its own tab copy — there is no language-selection control anywhere in the admin panel.
- **Current behavior:** The entire app (admin panel and the new Phase 12 public content API) is English-only in practice; `preferred_language` is collected but has no effect on anything.
- **Risk:** Low today (single-market dev state), but real the moment the platform expands to a market needing a second language — every content model would need a translation strategy (extra locale-suffixed columns vs. a separate translations table) decided before content, not after.
- **Why unresolved:** Multi-language support is an architecture-wide decision (which models need it, which translation strategy, whether it's admin-authored or professionally translated) spanning far more than CMS — not something a content audit should decide unilaterally.
- **Business decision required:** Whether/when multi-language support is needed, and which translation architecture to use.
- **Safe current default:** Single-locale (English) throughout; no structural blocker to adding translations later.
- **Affected modules:** CMS, Categories/Subcategories/Services, Banners, and potentially every other content-bearing model.
- **Blocked:** Yes, on a market-expansion/localization decision.

## 19. Soft-deleted `Service` cover images can accumulate as orphans

- **Issue:** Phase 12's audit confirmed `Service` uses `SoftDeletes`, and `deleteService()` deliberately does NOT delete the stored cover image file (documented in-code: "the file stays put deliberately, since restoring a trashed service should bring its picture back with it") — correct behavior for a *soft* delete. But no purge/prune job exists anywhere that ever hard-deletes an old soft-deleted `Service` row, so this is a narrow, currently-inert accumulation path: if such a job is ever added, it would need its own image-cleanup step, since none exists today.
- **Current behavior:** Soft-deleted services keep their cover image on disk indefinitely, by design; nothing hard-deletes a `Service` row today, so no image is currently orphaned in practice.
- **Risk:** Very low — no live consequence until a purge job is built, at which point it's a one-line addition, not a redesign.
- **Why unresolved:** Building a purge/retention job for soft-deleted records is a separate, unscoped feature — not part of a content audit.
- **Business decision required:** None — purely a reminder for whoever eventually builds a soft-delete purge job to also clean up the associated cover image.
- **Safe current default:** No action needed until a purge job exists.
- **Affected modules:** Services catalog.
- **Blocked:** No — not currently active; just a note for future work.

---

*Last updated: 2026-08-14, mission Phase 12 (CMS/content audit) — through commit range covering Phase 12.*
