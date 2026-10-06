# 1CF-COUPON-HARDENING-003 — report (05 Oct 2026)

Branch `feature/coupon-engine-c1-c3`, unpushed, nothing merged, no production contact, no migration run on `database/database.sqlite`.
Brief: `docs/COUPON_HARDENING_003.md`.

## 1. Files and lines changed (code)

| Area | File | Change |
|---|---|---|
| Guard (A) | `app/Services/Payments/OnlinePaymentGuard.php` (new) | ONE guard: `isOnline`, `assertOnline`, `legsAreOnline`, `assertLegsOnline`, `assertMethodUnchanged`; message `Offers apply on online payment only.` |
| | `app/Exceptions/OnlinePaymentRequiredException.php` (new) | extends `LogicException` (old guard's family) |
| | `app/Models/Booking.php` `booted()` | calls the guard; a child of a coupon bundle also cannot change method |
| | `app/Models/BookingBundle.php` `booted()` + `hasCouponBenefit()` | NEW mirror guard (section I) |
| | `app/Services/Coupons/CouponService.php` | cash check now `OnlinePaymentGuard::isOnline` (was a private list + old wording) |
| Scope (E) | `TargetMatcher::hasExplicitScope / isGlobal`, `GLOBAL_TYPE='global'` | blank targeting is not "everywhere" |
| | `CouponService::validate` | `no_scope`, `franchise_not_live` rejections |
| | `CouponAdminService::assertScope` | service-layer rule; `global` needs `coupons.approve` |
| | `Livewire/Coupons/Manage.php` + `manage.blade.php` | "Global / all eligible scope" checkbox, error display |
| Server-derived context (F) | `CreateBookingAction::resolveLocation` (+ call in `createWithinTransaction`) | franchise/zone derived from the customer's own address; mismatch throws `BookingContextMismatchException` |
| | `CreateBookingBundleAction` | wrapper row (anchor) derived through the same method |
| Modules (G) | `config/coupons.php` (new), `CouponService::validate` | `connected_modules = ['service']` + `ModuleActivationService::isActive` (not duplicated) |
| | `PromotionContext::$countryId`, `ServicePromotionContextBuilder` | country passed so the cascade sees country-level switches |
| Generic message (H) | `PromotionResult::GENERIC_MESSAGE`, `reject()`, `detail` | one customer text; real reason in `reasonCode` + `detail` |
| | `CouponException::customerPayload()` | same shape for every reason |
| | `CouponService::rejection()` | logs `coupon.rejected` {reason, detail, coupon_id, customer_id, module, franchise_id, payment_method} |
| Null vs zero (K) | `CouponAdminService::assertValid` | `usage_limit` joined `total_budget`/`daily_budget` (0 rejected in the service) |
| Super Admin settings (J) | `CouponSettings::save` (new, audited via `SettingsAuditor`) | `SuperAdminGate` in the domain call |
| | `Manage::saveSettings`, blade | form only for `super_admin`; `coupons.approve` no longer reaches it |
| Wording (L) | `manage.blade.php` | exactly `Visit and inspection charges are separate from coupon discounts.`; other visit/extras wording removed |

## 2. Tests added or changed

New files (all in `tests/Feature/Coupons/`): `CouponHardeningTest` (20), `CouponPaymentGuardTest` (22), `CouponGlobalSettingsTest` (5), `CouponLifecycleAccountingTest` (8).
Changed existing tests (only where the new rule requires it): `CouponEngineTest` (helper adds explicit global scope; cash message; generic message asserted in the expired/inactive loop; audit test passes a city target), `CouponAdminScreensTest` (form default `globalScope`; two raw coupons get a global row; daily-cap test passes global target; daily-cap wording now asserts the generic text while `reason` stays `daily_cap_reached`).

Brief test numbering → test:
1 `test_1_nellore…` · 2 `test_2_client_supplied…` (+2b zone, 2c address owner, 2d bundle child, 2e derived row) · 3 `test_3` · 4 `test_4` · 5 `test_5` · 6 `test_6` · 7 `test_7` · 8 `test_8` · 9 `test_9_a_coupon_bundle_parent…` (+ `test_i_every_bypass…`) · 10 `test_10` · 11 `test_11` (+`test_c_split_payment_does_not_exist…`) · 12/13/14 `test_12/13/14` · 15 `test_15` · 16 `test_16` · 17 `test_17` · 18 `test_18` · 19/20/21 `CouponGlobalSettingsTest::test_19/20/21` · 22-28 `test_22_to_28…` (data provider ×3 fields) + `test_28b` · 29/30 existing `CouponEngineTest::test_commission_and_payout_are_identical_with_and_without_a_coupon` (not duplicated; compares platform, provider and franchise amounts) · 31/32 `test_31_and_32…` · 33/34 `test_33…`/`test_34…` (same class) · 35/36 `test_35_and_36…` · 37/38 `test_37…`/`test_38…` · 39/40/41/42 `test_39…`–`test_42…` · 43 `test_43_coupon_benefit…` for coupon (see §9 for the other benefit types).
Probes from `scratchpad/ArchProbeTest.php.txt` committed where not duplicates: 15a→31/32, 15b→33, 15c→35/36, 14→`test_the_form_refuses_empty_targeting…`. Others were duplicates of 1, 3, 4, 8, 9, 20, 22-28, 2 and 5; the franchise_modules-column probe (9c) tested a table the engine does not use and was replaced by `test_5` (ModuleActivationService).

## 3. Counts

- `tests/Feature/Coupons`: 107 passed (was 52 at start).
- Affected suites after group (b): BookingBundle 68, Booking 45, Bookings 11, Payments 131, Cancellation 130, Dispatch 138, Pricing 31 — all green.
- Full suite after group (a): 3318 tests, 3317 pass; 1 failure `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` — **fails identically with my changes stashed** (pre-existing, environment related).
- Final full-suite count: see the end of the chat reply (run last).

## 4. Browser verification (scratch DB only)

Scratch SQLite file in the session scratchpad, migrated from zero (`DB_DATABASE` env override; `database/database.sqlite` untouched; coupon_targets did not even exist there). `php artisan serve` on 127.0.0.1:8765, test users seeded by a script that refuses to run on a non-scratch DB. Server stopped afterwards.

Verified in Chrome (screenshots saved by the tool): Super Admin login; list (empty state); page text shows the exact visit/inspection sentence; settings card visible and **saved** ("Coupon settings saved."; "AVAILABLE to customers"); new coupon form; **invalid daily cap 0** → "The daily cap field must be greater than 0"; **no scope** → "Choose where this coupon applies: pick a city, or tick Global…"; **global ticked + valid cap 150** → "Coupon created as a draft"; list row shows `₹0.00 / ₹150.00` daily cap and form shows `Funding: HQ`; **activate** with reason → "Coupon is now active", status Active.

NOT verified in the browser (tool flakiness: pause dialog did not open, coordinate clicks scrolled the page; stopped per the 2-3-attempt rule): pause, resume, archive, edit, permission-denial (approver / manager / viewer / franchise-scoped), and the settings card being hidden from a non-Super-Admin. Each of these is covered by Livewire feature tests (`CouponAdminScreensTest`, `CouponGlobalSettingsTest`, `CouponLifecycleAccountingTest`) but has no screenshot. Seeded users for a re-run: super/approver/manager/viewer/scoped `@scratch.test` in the scratch DB.

## 5. Exact rejection messages

- Any non-redeemable coupon (invalid code, inactive/paused, expired, not started, usage limit, budget, daily cap, per-user limit, minimum order, wrong city/zone/franchise/customer/module, module not connected/enabled, no scope, franchise not live, flash conflict, no discount): **`This coupon cannot be applied to this order.`** Payload `{"message": "..."}` via `CouponException::customerPayload()`.
- Cash / split / any non-online method: **`Offers apply on online payment only.`** (reason `online_payment_required`).
- Kept as its own text: kill switch (`Coupons are not available right now.`) — it is not about a coupon (Decision D2). Membership already covers the booking: `Your membership benefit already covers this booking.` (unchanged, thrown in `CreateBookingAction`; Decision D3).
- Internal reasons (log `coupon.rejected`, `PromotionResult::reasonCode`/`detail`): `invalid_code, inactive, exhausted, expired, not_started, not_targeted, excluded, no_scope, franchise_not_live, module_not_connected, module_not_enabled, over_per_user_limit, below_minimum, budget_exhausted, daily_cap_reached, flash_sale_conflict, entitlement_covered, no_discount, online_payment_required, coupons_unavailable`.
- **C3 must never send `reasonCode` to a customer.** Nothing catches `CouponException` in controllers/Livewire yet (grep: only `CreateBookingAction` throws it), so C3 owns that mapping.

## 6. Schema findings; what `coupons.franchise_id` means (§O)

`coupons.franchise_id` (nullable FK, "null = global coupon", migration 2026_08_01_031000) is the **owner/limiter**: HQ coupon = null; a value restricts redemption to that franchise (`CouponService::validate`, `TargetMatcher::hasExplicitScope`). `coupon_targets` is the **audience/place/product** filter (city, zone, franchise, customer, customer_type, categories, subcategories, services, plus the new `global` marker); it narrows within the owner. `funding_mode` (string, only `hq` selectable) is **who pays for the discount**, not who owns the coupon. Flash sales use `scope_type`/`scope_id` (with `ancestryFor()` for `AuthorizationService`) as owner scope plus a separate targets table — the same split (owner vs filter). No fourth concept is needed now.

## 7. Is an owner-scope migration required?

**No, not for this workstream.** HQ coupons (null) and franchise-owned coupons (`franchise_id`) cover every case the screens build (they only create HQ coupons). A `scope_type/scope_id` pair (country/city/zone owner, aligned with flash sales and `AuthorizationService`) becomes necessary only when country/city/zone-level admins are to **own and edit** coupons. If/when that is wanted: ADD `owner_scope_type varchar(20) null, owner_scope_id bigint unsigned null`, index `(owner_scope_type, owner_scope_id)`, backfill `franchise_id IS NOT NULL → ('franchise', franchise_id)`, null = HQ; affects `Coupon`, `CouponAdminService`, `Manage` scoping, `TargetMatcher` (none). Not written, not run.

## 8. Daily-cap EXPLAIN (§P) — BLOCKED

No MySQL on this machine (`mysql`, `mysqld`, docker, XAMPP/Laragon absent; `.env` is SQLite) and the brief forbids SQLite EXPLAIN and production credentials, so **no EXPLAIN was run**. I did not install a database.

Exact query (`CouponService::validate`, daily cap; same shape in `Manage::render` for the screen's "today" column):
```sql
SELECT SUM(discount_applied) FROM coupon_usages
WHERE coupon_id = ? AND status IN ('reserved','confirmed') AND reserved_at >= ?   -- ? = start of today IST, in UTC
```
Proposed (NOT run):
```sql
-- UP
CREATE INDEX coupon_usages_coupon_status_reserved_idx ON coupon_usages (coupon_id, status, reserved_at);
-- DOWN
DROP INDEX coupon_usages_coupon_status_reserved_idx ON coupon_usages;
```
Expectation to confirm on local MySQL: today the optimiser can use the `coupon_id` foreign-key index and filter the rest per coupon; the composite makes it a range scan on (coupon_id, status, reserved_at). Per-coupon row counts are small, so the benefit is real only at large redemption volume. To run: install/start a local MySQL 8, `CREATE DATABASE coupon_explain`, point a throwaway `.env.explain` at it, migrate, seed e.g. 200k `coupon_usages` rows over 5 coupons, `EXPLAIN FORMAT=TREE` the query before/after the index.

### Rehearsal plan — the six coupon migrations on MySQL
Order: `2026_08_12_002000_add_foreign_key_to_bookings_coupon_id` (base), `2026_10_04_100000_extend_coupons_for_engine`, `…100100_extend_coupon_usages_for_engine`, `…100200_create_coupon_targets_table`, `…100300_add_coupon_snapshot_to_bookings_and_bundles`, `2026_10_05_100000_add_daily_cap_funding_campaign_to_coupons` (+ permissions seed `…100400`).
1. Local MySQL 8 (same major version as production), empty DB, `php artisan migrate` to the commit before `…100000`, then load realistic rows: 50k `bookings`, 5k `coupons`, 20k legacy `coupon_usages` (with non-null `booking_id`, a few orphans).
2. Snapshot (`mysqldump`) **before** the run; time each migration; run `migrate:status` after.
3. **`…100100` special attention** (3 separate `Schema::table` calls): (a) `dropForeign(['booking_id'])` — verify the constraint name is the Laravel default `coupon_usages_booking_id_foreign` on MySQL and that no other index needs it; (b) `->nullable()->change()` rebuilds the column — on a large table this is a table copy/metadata lock, so measure it; (c) the FK is re-added with `cascadeOnDelete` — fails if any orphan `booking_id` exists: run `SELECT COUNT(*) FROM coupon_usages cu LEFT JOIN bookings b ON b.id=cu.booking_id WHERE b.id IS NULL` first; (d) `unique(booking_id)` — fails if a legacy booking has two usage rows: `SELECT booking_id, COUNT(*) FROM coupon_usages GROUP BY 1 HAVING COUNT(*)>1`. If (a) succeeds and (c) fails the table is left **without** its FK (DDL is not transactional in MySQL) — hence the pre-checks and the dump.
4. Run `down()` of `…100100` once on the rehearsal DB to prove the rollback path (it re-adds the FK and makes `booking_id` NOT NULL again — it fails if any NULL `booking_id` rows (bundle usages) exist; expected, document it).
5. Run the Coupons test suite against MySQL (`DB_CONNECTION=mysql`) once to catch SQLite-only assumptions (SQLite ignores `lockForUpdate`; design §16 has the manual two-session lock test).
6. Only after 1-5 pass: production gets a dump to `~/backups` per CLAUDE.md step 2, then `migrate --force`, and the SHOW COLUMNS check CLAUDE.md requires for enums does not apply (no enum widened here).

## 9. Cash audit — can a benefit reach a CASH booking today? (§Q / addendum 7)

| Benefit | Can it apply to cash? | Evidence |
|---|---|---|
| Coupon | **No** (after this work) | `CouponService::validate` → `OnlinePaymentGuard`; `CouponPaymentGuardTest::test_10`, `test_43`; `Booking::booted` guard |
| Coupon on a bundle | **No** | `CouponPaymentGuardTest::test_43`, `test_9`; new `BookingBundle::booted` |
| Referral code (as a discount) | Does not exist | no code path takes a referral code as a price reduction (`grep referral_code` → only user code generation / display) |
| Promotional discount, auto-applied offer, challenge coupon, bulk coupon | **Do not exist** as mechanisms | `grep -i "auto_apply\|bulk_coupon"` → nothing; "challenge" hits are QR/KYC challenges, unrelated |
| Wallet credit / referral credit / promotional credit | **No** — spending needs `payment_method='wallet'` | `CreateBookingAction::execute` debits only when `$paymentMethod === 'wallet'`; wallet is online. Referral credit shares the Main Wallet today (see memory note), no separate balance |
| **Flash sale** | **YES — violates the thumb rule** | Probe run: baseline cash price 500; with a 20% flash sale a **cash** booking is priced **400** (`flash_sale_cash_price: 400`, `payment_method: cash`, a `flash_sale_redemptions` row exists). Code: `CreateBookingAction::resolveAuthoritativePrice` → `FlashSaleService::effectivePriceFor` never reads `payment_method` |
| **Prime pricing discount** (percentage entitlement) | **YES — violates the thumb rule** | Probe: 30% member discount on a **cash** booking → price **350** (`prime_pricing_discount_cash_price: 350`). `CreateBookingAction` lines ~271/315 → `EntitlementService::resolveAndConsumeForBooking`; `grep payment_method` in `Plans/EntitlementService` → no hit |
| Prime Free Service Visit waiver | No (already enforced) | `PrimeWaiver.php:45,55`; `NoWorkVisitChargeRuleTest::test_a_cash_booking_gets_no_free_visit_and_none_is_consumed` |
| **Loyalty points earned** | **YES (earn side)** | `CompleteBookingAction` (~line 136) awards customer points on `price_final − coupon` with no `payment_method` check; `grep payment_method` in `LoyaltyService`/`CompleteBookingAction` → no hit. Redemption converts points to wallet credit (online spend) |
| **Referral reward** (to the referrer, when a referred customer's first booking completes) | **YES** | `ReferralService::qualifyFromCompletedBooking` has no payment-method check |

The probe test was temporary and is not committed (output above). **No change to flash sale, Prime or loyalty/referral was made. Each needs your approval** (Decisions D5-D8). Test 43 is satisfied for the coupon rows only; it cannot be satisfied for flash sale / Prime / loyalty / referral without changing their behaviour.

## 10. Extras and split-payment findings

- Extras: recorded in `booking_extra_items` (`status`, `amount`, `added_by_provider_id`; **no payment-method column**). `CompleteBookingAction` sums approved items into `price_final = price_quoted + extras`. There is **no payment record or method for extras anywhere**; nothing writes `bookings.payment_method` after creation (`grep "payment_method *=" app` → no assignment). So a later extra cannot change anything the coupon guard reads. `test_15` proves the original coupon stays valid and realised after an approved extra and that the guard stays strict. I did **not** relax the guard for extras — nothing needed relaxing. How extras are actually collected from the customer is not modelled (gap, see 14).
- Split payments: **do not exist** (`bookings.payment_method` enum `online|cash|wallet`; no cash-amount column; `payments` has no method column). Only a rejecting guard (`legsAreOnline`/`assertLegsOnline`) and tests were added; nothing built. Combined wallet + Razorpay is still "later" (WORK_QUEUE).

## 11. Country-scope findings (§Q)

`coupon_targets` has **no country type**, and `TargetMatcher::CONTEXT_TYPES = city, zone, franchise, module, customer, customer_type`. "All live franchises" (`global` marker) already reaches **every live franchise in every country** (it has no geographic filter), so a whole-country coupon = global + `exclude` rows for franchises elsewhere — workable but clumsy and it silently grows when other countries go live. If needed: add a `country` target type (no schema change — `target_type` is a string registry key; `PromotionContext::$countryId` now exists and is filled from the franchise). Cost: one `match` arm in `TargetMatcher::contextMatches`, one form control, tests. Not added (brief says do not).
"Live" = `franchises.status = 'active'` (`active|inactive|pending_setup`); evaluated at redemption time (`test_41` creates the franchise after the coupon; `test_42` proves `pending_setup`/`inactive` are not reached and that an exclude row wins).

## 12. Cancel-and-rebook trace (§B) — report only

Customer cancel is priced by `CancellationPolicy::evaluate` (status based, from the booking's frozen policy snapshot):
- no provider yet (`pending`/`searching_provider`): **free**. An unpaid coupon booking is in this state, so cancelling it costs nothing and `CouponService::onBookingCancelled` **releases** the reservation (budget + daily cap back; `test_34`).
- provider `assigned`: `cancellation.assigned_fee` (default 0). `provider_en_route`: `cancellation.en_route_fee` (default 0; Prime-waivable). Provider **arrived (verified)**: the visit charge (₹149 launch). Late provider (`provider_late_minutes`): free. `in_progress`: **cannot cancel**.
- "Free-minutes window": `cancellation.free_minutes` (default 15) exists but its own help text says it is used **only** when an operator cancels from the admin panel without waiving; the customer path does not use it.
- Refund of an online payment: `CancellationService::refundIfPaid` refunds `captured payment amount − fee` (the payment is already the discounted 400, not 500); wallet-paid → wallet credit; Razorpay → gateway refund to the original method. After a release the coupon no longer counts toward `per_user_limit`, so the customer could re-use it online.
- Rebooking in cash afterwards is a new booking at the full 500, no coupon (the guard rejects any coupon code with cash).
Not decided here: see D9.

## 13. Decisions needed (nothing below was invented; all ship null/off or as noted)

- D1 **Empty-scope definition.** Implemented fail-closed: a coupon is scoped only if it has `franchise_id`, an include on city/zone/franchise/customer, or the explicit `global` marker. `customer_type` alone (e.g. "new customers") is **not** a scope, so "new customers everywhere" needs the global tick. OK, or should `customer_type` count?
- D2 Kill-switch message (`Coupons are not available right now.`) is not made generic. Make it generic too?
- D3 `entitlement_covered` ("Your membership benefit already covers this booking.") stays specific; other specific ones (minimum order, per-customer limit, flash conflict) were made generic per "every non-redeemable coupon". Want any of them customer-visible again?
- D4 Log every rejection also to `activity_log`/admin diagnostics screen? Today: server log `coupon.rejected` only (an activity row per attempt could flood on typo spam; C3 rate limit is not built).
- D5 **Flash sale on cash bookings** (proved: 500→400). Block on cash?
- D6 **Prime percentage/pricing discount on cash bookings** (proved: 500→350). Block on cash?
- D7 **Loyalty points earned on cash bookings.** Block / zero on cash?
- D8 **Referral reward when the referred customer's first booking was cash.** Block / require online?
- D9 **Cancel-and-rebook-for-cash fee.** Today standard stage fees apply (assigned_fee/en_route_fee/visit charge). Should "cancelled only to switch to cash" be fee-free at every stage before work? Should refund of an online payment on such a cancel be to original method or wallet?
- D10 How extras are paid (no mechanism exists today) and whether extras may be paid in cash (brief says yes) — needs a design before any C-phase touches it.
- D11 `coupons.unpaid_hold_minutes`, `coupons.enabled` stay unset in production (coupons OFF); the Super Admin must set them. Franchise-scoped holders cannot open the screens (unchanged).
- D12 Mass-update bypass: `Booking::where(...)->update(['payment_method'=>'cash'])` skips model events (Eloquent limitation). Closing it needs a DB trigger/constraint (a migration) — approve or accept?
- D13 Scheduling this commit grouping: I committed **test-first then fix** per group (6+ commits) which is more than "four commits"; see 17.

## 14. Remaining gaps

Browser check incomplete (see 4); no MySQL EXPLAIN/rehearsal run (8); flash sale / Prime / loyalty / referral cash leaks (9); extras collection undefined (10); Eloquent mass-update bypass (D12); no customer-facing C3 yet (validate endpoint, rate limit, reason masking in responses); `coupons.view` screen does not show the internal rejection reasons (diagnostics = log only, D4); existing production coupons with empty targeting: local dev DB has 0 coupon rows and no `coupon_targets` table (C1 migrations not applied there). For production run, read-only, after the migrations: `SELECT id, code, status FROM coupons WHERE deleted_at IS NULL AND franchise_id IS NULL AND id NOT IN (SELECT coupon_id FROM coupon_targets WHERE operator='include' AND target_type IN ('city','zone','franchise','customer','global'));` — any row returned will start being rejected (`no_scope`) until given a scope. Coupons are OFF in production, so none can be redeemed today.

## 15. Do C1/C2 now satisfy the permanent coupon rules?

For **coupons themselves**: online-only (engine, Booking, BookingBundle, one guard), no cash route, no split route, extras don't invalidate, no empty scope, server-derived franchise/zone/module, generic customer message, Super Admin-only global settings, null/0/positive limits, pause/archive keep holds, historical snapshots immutable, commission/payout independent of the coupon — **yes**, with the caveats D12 and the browser gap. For the **platform-wide** THUMB RULE: **no** — flash sale, Prime pricing, loyalty earn and referral reward still reach cash bookings (D5-D8).

## 16. Is C3 safe to begin?

Safe for the coupon path, provided C3: (a) shows only `customerPayload()` text and never `reasonCode`; (b) adds the rate limit; (c) reuses `OnlinePaymentGuard` for any client-side hint. I recommend resolving D5-D8 (owner decisions on existing benefits) before or alongside C3 because C3's checkout will sit next to flash/Prime pricing that today also applies to cash.

## 17. Commit list

See the final chat reply (`git log --oneline`).
