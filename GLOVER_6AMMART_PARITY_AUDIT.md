# Glover / 6amMart Parity Audit (Mission Phase 13)

**This corrects `GLOVER_VS_1CALLFIX_AUTH_AUDIT.md`'s premise.** That earlier, narrower audit (auth/OTP/QR scope only) explicitly stated *"Neither [a Glover video nor documentation] was actually accessible in this session"* and labeled every comparison point `NOT ESTABLISHED BY REFERENCE`. This session found that the reference material **does exist on disk**, just outside the `1callfix-demo` git repo, in sibling folders on the same machine. That earlier document's own "Recommendation for the record" said exactly this should happen if real material ever became available — it has, and this document is that revisit, scoped to the full Phase 13 "Glover/6amMart parity audit" mandate rather than just auth.

## Sources used

| Source | Location | What it is |
|---|---|---|
| Glover backend | `D:\D-Downloads\version-1.8.50\references\glover-1.8.5` | Full extracted Laravel source tree (145 tables, models, routes, `docs/`) |
| Glover DB dump | `D:\D-Downloads\1CallFix Super App\1.8.5 Glover\glover-1.8.41.sql` | Real Glover schema + seed/config data (no live customer data) |
| 6amMart admin panel | `D:\D-Downloads\1CallFix Super App\6amMart Main app V 4.0.1\Admin panel new install V4.0.1` | Full Laravel admin backend (172 tables, `nWidart/laravel-modules` addons) + its fresh-install seed dump |
| 6amMart apps | same parent — `User app` (Flutter), `6ammart-react-user-website-v4.0.1` (Next.js), `6ammart-car-rental-module-addon` | Skimmed for structure only |
| **1CallFix's own real prior-version database** | `D:\D-Downloads\version-1.8.50\DB_1cal_app_1.8.10.sql` | **The most authoritative source** — see below |

### The 1.8.10 dump is not what it first appears to be

It is **real 1CallFix operational data** (real service catalog, real Nellore zone, real `test@1callfix.com`/`par@1callfix.com` accounts, real social links, real support phone number) sitting on top of a **licensed Glover/6amMart-lineage codebase that was rebranded as 1CallFix**, not an earlier version of the `1callfix-demo` codebase this mission has been building. Evidence: its `migrations` table contains zero rows matching any file in this repo's `database/migrations/`; it uses `spatie/laravel-permission`/`spatie/laravel-medialibrary`/Laravel Telescope, none of which this repo uses; leftover strings still read *"download the **Molina Market** app"* and reference **Glover** by name. Treat its **schema** as third-party vendor architecture, and its **populated business data** as genuinely 1CallFix's own — the two need different handling, and the sections below keep them separate.

**Handled as a secrets-bearing artifact**: the dump contains what appear to be live-looking Google Maps/Firebase keys and payment-gateway keys. None are reproduced in this document; all payment-gateway values quoted below are visibly `_test_`/sandbox credentials.

---

## 1. Feature/module gaps — Glover and 6amMart both have, 1CallFix does not

Both reference apps are broader multi-vertical marketplaces (food/grocery/pharmacy/parcel/taxi/property-rental/ecommerce); 1CallFix has one vertical (Service) by design. Skipping the vertical-specific gaps (taxi orders, product catalogs, property bookings — out of scope, a different business), the **cross-vertical infrastructure gaps** both references have that are relevant regardless of vertical:

| Capability | Glover | 6amMart | 1CallFix |
|---|---|---|---|
| Multi-currency (exchange rates) | `currencies`, `currency_exchange_rates` | `currencies` (~160 seeded) | None — `locale.currency_symbol` is display-only |
| Tax engine | none found | Full `Modules/TaxModule` + `taxes`/`tax_additional_setups` | None |
| Live coupon redemption | Yes — `GET /coupons/{code}` wired, `coupon_product`/`coupon_vendor`/`coupon_user` pivots | Yes — live at checkout | Schema exists (`coupons`/`coupon_usages`), **dormant**, risk item 7 |
| Live review/rating system | `reviews`/`ratings` (polymorphic) | Full moderated review pipeline | Was schema-only dormant — **closed this phase**, see §4 |
| Reporting/analytics suite | — | 5 dedicated earning/tax report controllers | Operations does anomaly *detection*, not periodic reports |
| Batch payout/disbursement runs | — | `disbursements` + `disbursement_details` (group many payees into one run) | `PayoutService` pays one payee per row, no batching |
| Admin-configurable SMS/notification provider | `sms_gateways` table (enable per vendor) | `SMSModuleController` (switchable from UI) | Code-only binding (`AppServiceProvider`), risk item 8 |
| Social login (Google/Apple/Facebook) | — | Yes | None — OTP + QR pairing only |
| Wallet bonus / cashback engines | — | Both, separate from coupons/loyalty | Neither |
| Multi-language, live | Vendor-type names stored as `{"en":...}` JSON (schema i18n-ready; only `en` ever populated) | `translations` table + `business_settings.language` JSON array, live | Confirmed single-locale, `users.preferred_language` dead — risk item 18 |

**1CallFix has, that neither reference does**: scope-based RBAC (global/country/city/zone/module/franchise, additive), a franchise/territory ownership model (neither reference has a franchise concept at all — both are flat multi-vendor marketplaces), KYC video verification + 30-day deadline/reminder lifecycle, a granular compensation engine (tips/overtime/night/peak/rain/waiting), payment-webhook receipt logging + admin reprocess, scheduled-task run-history tracking, and dispatch-health/reconciliation/stuck-booking detection. None of these have any counterpart in either reference codebase.

---

## 2. Real historical 1CallFix data found in the 1.8.10 dump

This is the most valuable output of this audit — **real business values from an actual prior deployment of this same product**, categorically more authoritative than a competitor app's shipped defaults. None of these were silently adopted as new code defaults (see §5 for why) — they're presented as evidence for a human decision.

| Data point | Real value found | Dump location |
|---|---|---|
| Referral reward | **₹10 flat**, paid on registration (not first completed booking), `enableReferSystem='1'` | `settings` keys `referRewardAmount`, `enableOnRegistrationReferReward` — **`referrals` table has 0 rows: enabled, never actually paid out** |
| Platform commission | **20%** admin / 80% vendor split | `settings.vendorsCommission='20'`, corroborated by every `commissions`/`earning_reports` row |
| Driver commission | **12%** configured, but **every actual `commissions` row shows `driver_commission=0.00`** — configured, never applied | `settings.driversCommission='12'` |
| Cancellation charge | **₹199 minimum visiting charge** if cancelled after the technician visits — but this only ever existed as prose inside service descriptions, never a configurable `Setting` | `services` table row 1 description text |
| SMS/push vendor selection | **Firebase** (OTP + FCM push, real project id `onecallfix-6b538`) + **MSG91** and **GatewayAPI** both active | `settings.otpGateway='firebase'`, `sms_gateways` rows 2 &amp; 3 `is_active=1` |
| Payment gateway selection | Of 13 configured gateways, only **Cash, RazorPay, Wallet Balance** were `is_active=1` — everything else (Stripe, Paystack, Flutterwave, PayPal, PayTm, PayU, Billplz...) present and disabled | `payment_methods` table |
| Dispatch tuning | 5 km search radius, 20s alert window, 5 providers notified at once, 3 concurrent jobs per driver cap, **`autoassignmentsystem='0'` — auto-assignment was OFF in the real deployment** | `settings` (`driverSearchRadius`, `alertDuration`, `maxDriverOrderNotificationAtOnce`, `maxDriverOrderAtOnce`, `autoassignmentsystem`) |
| Real service catalog | **140 real services with real ₹ pricing** under 6 categories / 30+ subcategories (Split AC Service ₹900→₹800, AC Repair ₹800→₹699 "starts from", Washing Machine Service ₹599→₹499, Home Shifting, Desktop OS Install, Lock Smith...) | `services`/`categories`/`subcategories` tables — **candidate seed data for Phase 14 (QA/realistic data expansion), not imported this phase** |
| Real notification copy | *"Searching for Fixer"*, *"Expert is on the way to your location"*, *"order has been completed successfully"* — 1CallFix's own authored voice | `settings` keys `order.notification.message.*` |
| Real operating zone | Nellore, AP — center `14.433758, 79.982191`, radius 8.49 km | `delivery_zones` row 1 |
| Country ambition | `appCountryCode='INTERNATIONAL,GH'` — India **and Ghana** were both configured as target countries | `settings.appCountryCode` |
| Terms &amp; Privacy | **Never written**, even with a live Play Store app (`com.call.customer`) already shipping. Marketing copy (About Us, Contact Us, driver/vendor join descriptions) was authored; legal content was not. | Full-text search for `terms|privacy|policy` across the entire dump: **zero matches** |
| `payment_methods` vs. Settings-toggle question (risk item 11) | In the real deployment, `payment_methods` **was** the sole toggle store — no parallel `payment.*_enabled` setting existed anywhere. It also carried a `payment_method_vendor` pivot for per-vendor availability. | Confirmed absence of any `payment.`-prefixed settings key |
| `partner.workers.assign` authorization model (risk item 16) | The real deployment used **one unified RBAC** for every actor — admins, vendor managers, drivers, and customers all drew from the same 6 roles / 108 permissions, including actor-facing permissions like `my-earning`, `view-my-bookings`, `order-assign-driver` living in the same table as admin-panel permissions. | `roles`, `permissions`, `model_has_roles` |

---

## 3. Neither reference resolves

Explicitly confirmed **not** resolved by either 6amMart or Glover or the real 1.8.10 dump — all three independently confirm these were never decided, anywhere, ever, not even in a real production deployment of this product:

- **Tips/waiting/rain/overtime/peak/night compensation rate structures** (risk item 5) — the 1.8.10 dump's `orders.tip` column exists and every single row is `0.00`; no rate-related setting key exists anywhere in 267 real config rows. 6amMart's and Glover's shipped defaults are also 0/off.
- **Worker compensation model** (risk item 6) — same evidence; the one number found (driver commission 12%) was configured but never actually applied to a single real order.
- **Performance/Growth Campaign reward values** (risk item 4) — no campaign/incentive/target table exists in any of the three references.
- **Coupon launch decision** (risk item 7) — Glover and 6amMart both have coupons *live*; the real 1.8.10 1CallFix deployment had the schema built and **zero rows**, same dormant state as today.
- **Flash Sale × Coupon × Badge stacking** (risk item 12) — empty in all three.
- **30-day KYC deadline scope for Workers** (risk item 13) — the real prior deployment's entire KYC apparatus was one generic `document_requests` queue with no deadline concept for anyone, Partner or Worker. 1CallFix's current KYC engine is already far ahead of what a real deployment of this product ever had.
- **Per-country KYC document requirements** (risk item 14) — no requirement-config table existed at all in the real deployment.

## 4. What this phase closed (matching the established "wire the orphaned code" pattern)

**Reviews.** `reviews` has existed since Phase 1 — real schema, real `Review` model, a real `ReviewObserver` that recomputes `providers.rating_avg` on save/delete, registered in `AppServiceProvider::boot()` — with **zero `Review::create()` call sites anywhere**, confirmed independently by this audit's 6amMart comparison (which has a fully live, moderated review pipeline 1CallFix's dormant schema clearly mirrors) and by a direct codebase grep. `providers.rating_avg` has been shown on the admin Provider screen since Phase 1 and has been permanently `0.00` the entire time. Same class of gap as Tips (Phase 11) and the CMS/Banners consumer gap (Phase 12) — schema + model + observer real and correct, only the write path missing.

Closed with:
- `App\Services\ReviewService` — `submit()` (customer, one review per completed booking, real DB unique constraint on `reviews.booking_id` plus an application check for the fast path) and `reply()` (provider, ownership-checked).
- `App\Http\Controllers\API\ReviewController` — `POST /api/bookings/{id}/review`, `POST /api/reviews/{id}/reply`.
- `Providers\Show` (admin) now renders the actual review rows alongside the `rating_avg` figure that was always there but never had real data behind it.
- 9 new tests (`tests/Feature/Reviews/ReviewTest.php`).

## 5. What was deliberately NOT built, and why

Every real value found in §2 is evidence for a human decision, not something this audit adopted unilaterally — doing so would silently make a business call the mission's own discipline (see every prior phase's `KNOWN_RISKS_AND_DECISIONS.md` entries) explicitly reserves for a person:

- **₹10 referral / 20% commission / 12% driver commission / ₹199 cancellation charge** — real historical numbers, not copied into any `Setting` default. `KNOWN_RISKS_AND_DECISIONS.md` items 1, 5, 6 updated with this evidence (see below) but their code defaults (0) are unchanged.
- **`payment_methods` vs. Settings-toggle consolidation** (item 11) — the real deployment's precedent (`payment_methods` as sole source of truth, no parallel toggle) is real evidence, but *acting* on it means retiring the Settings `payment.*_enabled` fields this mission itself built in Phase 11 and touching the live New-Booking-modal payment-method flow — a real behavior change with migration/rollout implications, not a same-phase audit fix. Flagged with the new evidence, not executed.
- **`partner.workers.assign` authorization model** (item 16) — the real deployment's "one unified RBAC for every actor" precedent is genuine prior art, but choosing 1CallFix's own default grant policy for Provider accounts is still a decision a human needs to make, not infer from a different codebase's choice.
- **MSG91 + Firebase as the real prior SMS/push vendor selection** (item 8) — real evidence of what was actually chosen and paid for, but building live adapters requires actual current credentials this session doesn't have (the dump's Firebase token is expired) and wasn't asked for; flagged as the concrete target for whenever real credentials are available.
- **The 140-row real service catalog / real notification copy / real zone geometry** — genuinely valuable seed data, explicitly earmarked for **Phase 14 (QA/realistic data expansion)**, the mission's own next phase, rather than imported piecemeal here.
- **Multi-language JSON-column architecture precedent** (item 18) — real evidence of *how* a prior version approached i18n (translatable JSON columns, not a separate table), useful the day the business actually decides to localize — not built speculatively now.

---

*This document is a point-in-time audit (mission Phase 13, 2026-08-15). See `KNOWN_RISKS_AND_DECISIONS.md` for the items it updated, and `CURRENT_MASTER_CHECKPOINT.md`/`PROJECT_CURRENT_STATE.md` for how this phase fits the overall mission.*
