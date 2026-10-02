# Coupon Engine — Audit and Design (Step 1)

Status: **DESIGN ONLY — awaiting approval. No code, no migrations written.**
Date: 2026-10-02 · Base: `main` @ `378ec28` · Author: Claude (audit of the working tree)

Every `file:line` below was read in this session. Where something could not be verified
from the repo (production data, prod DB state) it is marked **UNVERIFIED**.

---------------------------------------------------------------------------------------------

## 00. APPROVED DECISIONS (2026-10-02) — these SUPERSEDE anything later in this document

Source: owner message of 2026-10-02 ("Thumb rule + referral/dispatch investigation + coupon decisions").
Where the older text below disagrees (notably §3.4, §12 C1 row, §14), **this section wins.**

| # | Decision | Design consequence |
|---|---|---|
| Q1 | **D1 approved.** `price_quoted` stays gross; separate discount column; six charge sites → one `amountPayable()`; commission/payout unchanged. | §3.1 stands. Test: payout and commission identical with vs without coupon. |
| Q2 | **Cash is replaced by the THUMB RULE** (CLAUDE.md, commit `c9a80ff`): coupons are online-only in every phase, permanently. | §3.4 option A (cash reimbursement) is **deleted**, not deferred. No cash path ever needs reimbursement. |
| Q3 | **Fund ledger approved** (append-only, cost allocation only; real money only via `WalletService`). | Add to C4: **reconciliation report — fund ledger vs wallet movements** (§6.5 below). |
| Q4 | **Extend `coupon_usages` approved.** | `up()`/`down()` shown for approval before any migration is written (§2.2 stands). |
| Q5 | "Before any work" = **any status before `in_progress`** (pending, searching, assigned, en route, arrived). A cancel at arrival with the visit fee still **releases** the usage. | Replaces the §7 definition (arrival/interim gate). `consumed` now applies only to cancels from `in_progress` onward. |
| Q6 | Cancellation fee basis = **gross price (policy snapshot)**, never discounted. Refund = amount paid − fees; if fees exceed amount paid use the **existing collect-balance flow**. | §13 risk 4 resolved. Test: fee > paid → collect-balance flow, not a negative refund. (Collect-balance flow lives in the new cancellation policy; its exact entry point to be cited in C1.) |
| Q7 | **Loyalty on the amount actually paid.** | New scope: `CompleteBookingAction.php:135` changes from `price_final` to paid amount (`amountPayable()` + extras actually paid). Part of C1, with its own test. Supersedes §13 risk 3. |
| Q8 | **One discount per booking.** If a coupon is entered on a booking that already has a Prime/member discount, apply **whichever is larger** and show the customer which was used. Never both. | Replaces "entitlement-covered ⇒ always reject" (§3.2). Needs an entitlement **preview** (read-only) so the comparison happens before consumption: `EntitlementService::resolveAndConsumeForBooking` (`:40-64`) consumes as it resolves, so C1 adds a read-only `previewBestPricingEntitlement()` and `CreateBookingAction` chooses coupon-vs-member, then consumes only the winner. Coupon never applies on a booking covered by a *quantity redemption* entitlement. Snapshot stores which benefit won and the loser's value. |
| Q9 | Daily cap: on reaching it, **pause until midnight IST, auto-resume**. Reservations count; releases give it back. Customer sees "today's limit reached, try tomorrow". | Replaces §6.4 daily-cap wording. Computed, not a status write: `daily_cap_reached` reason until 00:00 `Asia/Kolkata` (the digest already uses IST: `DailyDigestDispatchService.php:57`). |
| Q10 | `coupons.enabled` stays **off in prod**. **Per-coupon total budget cap moves into C1** so an HQ-funded pilot with a hard budget can run after C3 while C4 is built. Pilot needs explicit go-ahead. | `total_budget` enforced in C1 (reserve under row lock; auto-exhaust). Fund ledger still C4. |

### 00.1 Online-only enforcement (from the THUMB RULE)
- `CouponService::validate()` **and** `reserve()` reject when `payment_method` is not an online method, with the exact message **"Coupons are valid only for online payments"** (reason code `online_payment_required`). Server-side, in the engine, not in the form — so web, API, Flutter, admin all inherit it.
- Online = `online` (Razorpay), `wallet`, or wallet + Razorpay. **Gap:** the code has only `online | cash | wallet` (`Setting::enabledPaymentMethods`, `app/Models/Setting.php:102-108`); there is **no combined wallet + Razorpay method today**. The rule's definition includes it; building it is a separate feature — flagged, not assumed.
- **Payment method is write-once.** `payment_method` is assigned only at creation (`CreateBookingAction.php:184`, `CreateBookingBundleAction.php:119,134`; no update path exists in `app/`). The design keeps it that way and adds a model-level guard (updating `payment_method` on a booking with `coupon_id`/benefit throws) plus a test, so a future provider-app/admin screen cannot flip it.
- **No pay-later with a coupon.** Today an `online` booking is created immediately with `payment_status=pending` and the Razorpay capture arrives later (`RazorpayWebhookHandler.php:75-76,174`). **Instant bookings are dispatched before payment** (`CreateBookingAction.php:89`; only *scheduled* bookings are payment-gated via `ScheduledDispatchService::releaseIfEligible`, `:87`). For a coupon booking this is a hole: a discounted job could be dispatched and worked unpaid. C1 therefore:
  1. holds the usage as `reserved` with a **reservation expiry** (`coupons.unpaid_hold_minutes`, a Super Admin setting — **null = no coupon bookings allowed**, since no value is invented);
  2. **gates dispatch on capture** for any booking carrying a coupon (reuse the scheduled-dispatch payment gate);
  3. a sweep job (same pattern as `DispatchDeadlineSweepService`) releases usage + fund reservation and **cancels the unpaid coupon booking** on expiry, so no discounted booking survives without payment. *(Decision needed — Q11.)*
  4. wallet-paid coupon bookings are captured inside the creation transaction (`CreateBookingAction.php:72-74`), so they never reach the sweep.
- **Extra work/parts** after a benefit booking are outside the benefit and may be paid by any method (`CommissionService.php:97-109` already treats extras as a separate receivable); the coupon never touches `extras` (§3.1).
- Super Admin has **no per-booking override** — there is deliberately no permission slug or setting for it.

### 00.2 Tests added to the §12 list (thumb rule)
Coupon rejected on cash booking (web and API) · client cannot send `payment_mode=cash` with a coupon · coupon booking cannot switch to cash after payment · unpaid online coupon booking releases the reservation · wallet balance/promotional credit rejected on cash booking · provider payout and commission identical with and without benefit · extra work after a benefit booking can be paid by cash.

### 00.3 Open items (new)
- **Q11.** Unpaid-hold expiry: cancel the unpaid coupon booking (recommended) or let the customer pay later at full price? And the hold length (`coupons.unpaid_hold_minutes`) — your value.
- **Razorpay webhook amount check** — see "UNVERIFIED" result in §0.4.

### 00.4 Verified this session
- **Razorpay webhook captured-amount check: NOT PERFORMED.** `RazorpayWebhookHandler::handleCaptured()` (`app/Services/Payments/RazorpayWebhookHandler.php:27-77`) reads only `order_id` and the payment `id` from the payload (`:29-30`) and marks the `Payment` row captured (`:75-77`); **it never compares `payload.payment.entity.amount` with `payments.amount`** (no `amount` reference anywhere in the file). The signature IS verified (`PaymentController.php:106-107`). Mitigation in practice: the Razorpay order is created server-side with a fixed amount (`RazorpayPaymentDriver.php:98-109`) and Razorpay will not capture a different amount against an order, so exploitability looks low — but the check is absent and **becomes more important once `amountPayable()` differs from `price_quoted`**. **Reported as a separate security fix, NOT part of coupons. Stopping for your approval before touching it.**
- **Local MySQL for the lock test: not available** — `mysql` is not on PATH, no `mysqld` found, nothing listening on 3306; `.env` uses SQLite (`DB_CONNECTION=sqlite`) and `phpunit.xml:41-42` uses `sqlite :memory:`. Manual MySQL steps are in §16.
- **Prod coupon row counts and `users.phone` uniqueness: awaiting your phpMyAdmin results.** Queries to run: `SELECT COUNT(*) FROM coupons; SELECT COUNT(*) FROM coupon_usages; SELECT COUNT(*) FROM bookings WHERE coupon_id IS NOT NULL; SHOW INDEX FROM users WHERE Column_name='phone';`

---------------------------------------------------------------------------------------------

## 0. Headline findings (read these first)

1. **Coupons are a dormant schema. There is no redemption code anywhere.** `coupons` and
   `coupon_usages` exist; nothing in `app/` creates a `CouponUsage`, validates a code, or
   applies a discount. No admin screen, no API route, no customer UI. `ModuleCapabilities`
   marks `coupons => true` for `service` only because the schema exists
   (`app/Support/ModuleCapabilities.php:52-62`, its own comment says "customer-facing
   redemption path is dormant"). So this is a build, not a retrofit — but it also means
   there is no legacy behaviour to break.

2. **`price_quoted` is the gross price everywhere money is split, and the charge amount
   everywhere money is collected. Both cannot stay true once a coupon exists.**
   - Read as *gross* (commission, payout, fee base, refund math, loyalty, campaigns):
     `CommissionService.php:76`, `CancellationService.php:70`, `:183`,
     `BundleSettlementService.php:224-227`, `CompleteBookingAction.php:79,135`,
     `CampaignMetricResolver.php:52`.
   - Read as *amount to charge*: `CreateBookingAction.php:263` (wallet debit), `:274`
     (Payment row), `RazorpayPaymentDriver.php:109`, `PaymentController.php:40`,
     `BookingBundlePaymentService.php:179`, `CreateBookingBundleAction.php:230,238`.

   Overwriting `price_quoted` with the discounted price (the obvious implementation) would
   silently cut provider payout and platform commission — violating your rule. **Design
   decision D1 (§3.1): `price_quoted` stays the pre-coupon price; add an explicit
   `coupon_discount_amount`; the ~6 charge sites switch to a single `amountPayable()`.**

3. **The existing wallet cannot be a fund.** `wallets.user_id` is a unique FK to `users`
   (`2026_08_01_023000_create_wallets_table.php:14`), `WalletService` has credit/debit only —
   no holds/reservations, no account type (`WalletService.php:20-101`). A fund needs
   *reserve → confirm → release*. See §6 for the recommended ledger and the one place this
   deviates from "all money through the wallet service" (needs your explicit OK).

4. **Cash bookings + coupon is a real money problem** (§3.4): the provider collects the
   *discounted* cash but still owes platform + franchise commission calculated on the *full*
   price (`CommissionService.php:120-139`). Provider ends up short by exactly the discount.

5. **Bundle refund math will break with a coupon unless child discounts are allocated**
   (`BundleSettlementService::refundDue`, `:220-230`, compares the *discounted* bundle
   payment against *gross* child `price_quoted`).

6. **The test suite appears to run on SQLite** (migration comments reference sqlite,
   `2026_08_12_002000_...php:31`). `lockForUpdate()` is a no-op there, so the
   "last redemption used twice" test cannot prove locking in CI. See §12.

---------------------------------------------------------------------------------------------

## 1. Audit

### 1.1 Coupon schema as it exists

| Item | Where | What it is |
|---|---|---|
| `coupons` | `database/migrations/2026_08_01_031000_create_coupons_table.php:12-26` | `franchise_id` nullable (null = global), `code` unique, `discount_type` enum(`flat`,`percent`), `value`, `min_order_value` default 0, `max_discount` nullable, `usage_limit` nullable, `per_user_limit` default **1**, `valid_from/until`, `is_active`. No name, status, budget, targeting, funding, campaign, created_by. |
| `Coupon` model | `app/Models/Coupon.php:15-29` | fillable mirrors the table; only relation is `franchise()`. |
| `coupon_usages` | `…_032000_create_coupon_usages_table.php:12-19` | `coupon_id`, `user_id`, `booking_id` (**all NOT NULL**, booking cascade-delete), `discount_applied`. No state (reserved/confirmed/released), no code id, no fund link. |
| `CouponUsage` model | `app/Models/CouponUsage.php:15-24` | plain relations. |
| `bookings.coupon_id` | `…_017000_create_bookings_table.php:30` (nullable, originally no FK); FK added `…_08_12_002000_add_foreign_key_to_bookings_coupon_id.php:22-25` (`nullOnDelete`). Its docblock records prod `bookings` had 0 rows / 0 non-null coupon_id at that time. `Booking::$fillable` includes `coupon_id` (`Booking.php:61`). **Nothing writes it.** |
| Marketplace | `…_08_19_007000_create_marketplace_orders_table.php:67-68`: `coupon_code` string, `coupon_discount_amount` decimal, comment "NOT wired to live Coupon redemption (item 12 stacking rules undecided)". `MarketplaceOrder::$fillable` `:35`. |
| Notification campaigns | `…_08_11_021000_create_notification_campaigns_table.php:40` `coupon_id` FK nullOnDelete; `NotificationCampaign.php:14,25` relation. Used for "send this coupon to an audience". Nothing else reads it. |
| `ModuleCapabilities` | `app/Support/ModuleCapabilities.php:62` `service.coupons = true`; every other module `null`. Documentation map, not enforcement (its own docblock `:5-16`). |
| Flash sale reuse of vocabulary | `FlashSaleService.php:18`, `…flash_sales_table.php:40-42` — flash sales deliberately reuse `flat/percent/max_discount`. The coupon engine should keep the same vocabulary. |

**Not verified:** whether prod `coupons` / `coupon_usages` contain any rows (prod SSH is
blocked from this session — see memory `prod-ssh-blocked-from-session`). Phase C1's
migration must be preceded by `SELECT COUNT(*)` on both, run by you on prod.

### 1.2 The booking pricing cascade

`CreateBookingAction::createWithinTransaction()` (`app/Actions/CreateBookingAction.php:136-221`),
the single choke point for single bookings **and** every bundle child:

1. `:138-159` service/franchise load, module-activation gate.
2. `:172` `resolveAuthoritativePrice()` → `:238-251`. An explicit `price_quoted` in `$data`
   is honoured (admin call-centre negotiated price, `:240-242`); otherwise
   `FlashSaleService::effectivePriceFor()`.
3. `FlashSaleService::effectivePricesFor()` (`:176-198`): `Service::resolvePrice($franchiseId)`
   (franchise override → discount_price → base_price) then the flash-sale layer, which
   "wins outright rather than stacking" (`:200-216`).
4. `:174-186` `Booking::create(['price_quoted' => $basePrice, …])`.
5. `:197-205` flash redemption recorded in the same transaction
   (`FlashSaleService::redeem()` `:338-373` — `lockForUpdate()` on the sale row, quantity and
   per-customer limit checked under lock; **this is the pattern coupons should mirror**).
6. `:212-218` **Prime/Plan entitlement runs AFTER the row exists and can rewrite
   `price_quoted`** (`EntitlementService::resolveAndConsumeForBooking`, `:40-64` — it *consumes*
   as it resolves; there is no read-only preview).
7. `execute()` `:52-111`: wallet payment inside the transaction (`:72-74`, `payWithWallet`
   `:253-285`), then dispatch, notifications.

Server-authoritative pricing is already enforced: `StoreBookingRequest` accepts no price
(`BookingController.php:53-59` comment), and the tests live in
`tests/Feature/Pricing/{PricingAuthorityTest,BundlePricingAuthorityTest,FranchisePricingBoundaryTest}.php`.
The coupon layer will follow this contract: the client sends **a code only**, never an amount.

### 1.3 Bundles

`CreateBookingBundleAction::execute()` (`app/Actions/CreateBookingBundleAction.php:80-193`):
one `BookingBundle`, N children each created by `createWithinTransaction()` (`:126-137`,
"deliberately NO price_quoted"), `total_price_quoted = round(Σ child price_quoted, 2)`
(`:139-144`), ONE aggregate wallet debit (`:147-149`, `payBundleWithWallet` `:220-250`),
ONE `Payment` (`purpose=booking_bundle`). Gateway amount = `total_price_final ??
total_price_quoted` (`BookingBundlePaymentService.php:179`). Idempotency key + a
`request_fingerprint` (`:89-97`, `Wizard.php:266`) — **the coupon code must be added to the
fingerprint** or a retry with a different code would replay the old bundle.

Refund: `BundleSettlementService::refundDue()` `:220-230` — `paid − Σ retained`, retained =
`cancellation_fee` for a cancelled child, `price_quoted` otherwise, and `reconcileRefund()`
`:147-213` is the idempotent delta refund (see its D3 ref scheme `:57-66`).

### 1.4 Commission and provider payout

`CommissionService::applyForBooking()` (`app/Services/CommissionService.php:67-198`):
- base `total = price_final ?? price_quoted` (`:76`);
- `platform = total × rate`, `franchise = total × commission_value` (revenue_share only),
  `provider = total − platform − franchise` (`:82-88`);
- non-cash: provider wallet credit + franchise owner credit (`:140-163`), refs
  `booking:{id}:provider-earning` / `:franchise-earning`;
- cash: provider **not** credited; a `ProviderCommissionReceivable` row = platform + franchise
  portions is recorded and later swept at payout request (`:120-139`);
- extra-work on prepaid bookings makes a second receivable (`:97-109,166-177`);
- idempotent by existing `Commission` row (`:69-72`).

`price_final = price_quoted + approved extras` is set at completion
(`CompleteBookingAction.php:78-81`). **Conclusion: if `price_quoted` stays gross (D1) the
commission and payout code needs ZERO change and is provably identical with/without a coupon.**
The coupon sits wholly on the *collection* side (what the customer pays) and the *marketing
cost* side (who funds the gap). That is exactly your funding rule.

### 1.5 Cancellation

- Fee: `CancellationService::calculateFee()` `:54-72` → `calculateFeeGeneric()` `:171-186`,
  basis `price_quoted` (gross), capped at the basis. Waived when no provider assigned (`:56-58`).
- Refund: `refundIfPaid()` `:214-274`: `refund = payment.amount − fee`, floor 0 (`:226-234`),
  to wallet or gateway. **Already "based on what the customer actually paid"** — because
  `payment.amount` will be the discounted figure.
- The newer customer cancellation policy (mid-work lock, interim charge, settle-before-cancel)
  is in `app/Services/Cancellation/*` and `CustomerCancelBookingAction`; the policy snapshot
  is a JSON column on bookings (`2026_10_02_002000_cancellation_policy_phase2.php:20`
  `cancellation_policy_snapshot`). **The coupon snapshot copies this pattern.**
- `CommissionService::applyForCancelledBooking()` `:419-468` pays the provider on
  interim-work/visit-fee charges, on the *charge* — untouched by coupons.

### 1.6 Invoices / GST (for your CA)

- `DocumentService::forPayment()` `app/Services/Documents/DocumentService.php:59-97`:
  `total = payment.amount` (`:89`); for `purpose='booking'` the lines are **one line, label
  "Service: …", amount = payment.amount** (`:151-154`).
- There is **no discount line, no taxable-value field, no tax/GST field** anywhere in the
  booking invoice path. `CancellationDocumentService.php:20` states the same for the
  cancellation invoice: a single line, no GST.
- Therefore today: invoice total = what the customer paid. A coupon would make the invoice
  show the *discounted* amount with no explanation, and no taxable value is computed at all, so
  "does the coupon reduce taxable value" is currently *not a question the system can answer —
  it computes no tax*.

**Proposal (no tax invention):** invoice shows three lines — `Service (gross)`,
`Coupon <CODE> (−₹x)`, total = amount paid. Frozen from the booking snapshot, not
recomputed. **Questions for the CA** (I will not decide these):
(a) Is the company-funded discount treated as a reduction of the consideration/taxable value,
or as a marketing expense separate from the supply? (b) Does the answer differ when a
*franchise* fund pays vs HQ vs a third party? (c) If GST is introduced to invoices later,
which document carries the pre-discount value? Until answered, the engine stores gross,
discount and net separately so either treatment can be produced without data loss.

### 1.7 Roles, permissions, scope

- Roles/permissions are DB-driven: `roles`, `permissions`, `role_assignments`
  (`Role.php`, `Permission.php`, `RoleAssignment.php`). Default seed `…_08_11_016000_seed_default_roles_and_permissions.php`
  defines system role **`franchise_owner`** (`:50`, "Operates their own franchise's bookings and
  providers") and a **Marketing** permission group (`banners.manage`, `cms.manage`, `:31-32`).
  No dedicated marketing role exists — assigning permissions to a custom role is the supported path.
- Each assignment carries `scope_type`/`scope_id`; `AuthorizationService::scopeCovers()` and
  `can()` (`app/Services/AuthorizationService.php:23-150`, grant-scope restriction `:87-116`) are
  how every feature scopes a franchise user. `Flash Sales` and `Performance Campaigns` already
  follow `*.view / *.manage / *.approve` slugs
  (`…_08_14_010000_seed_flash_sales_permissions.php:13-14`,
  `…_015000_seed_performance_campaigns_permissions.php:17-19`). Coupons follow the same pattern.
- No "franchise marketing" field exists on `franchises` (grep for marketing/fee on the
  franchises schema found only `commission_model` ∈ `revenue_share|flat_fee|subscription_only`,
  `2026_08_01_001000_create_franchises_table.php:20`).

### 1.8 Ledger / wallet support for a fund

- `wallets`: `user_id` unique, `balance decimal(10,2)`; `wallet_transactions`: `amount`,
  `is_credit`, `reason`, `ref` **unique** (idempotency), `status`
  (`…_023000/024000`; `actor_id` added later — `WalletService.php:97`).
- `WalletService::applyTransaction()` `:53-101`: row lock, **hard no-negative guard**, freeze
  policy (`:73-77`), idempotency by `ref`. **No hold/reserve concept, no non-user account.**
- Money-source labelling: `app/Support/WalletSourceLabel.php` (already knows about booking refs).
- Audit: `activity_log` is **append-only** (`ActivityLog.php:7-17`, update/delete throw) via
  `ActivityLogger::log/logModel` (`ActivityLogger.php:22-37`). Settings changes already log
  old→new (`ActivityLog.php:35+` describes the "x: 100 → 150" format) and
  `CancellationPolicy\Manage.php:279` renders an audit pane. **The coupon audit trail uses this,
  no new audit table.**

### 1.9 Performance Campaigns — can it model "complete 10 deliveries, earn ₹200"?

Report only (out of scope to build):
- It is an **incentive engine, explicitly NOT notification campaigns**
  (`…performance_campaigns_table.php:7-15`). Audiences: `franchise|provider|field_worker|customer`
  (`:29`); qualification `threshold` (target_value) or `top_n` (`:47-49`); reward
  `wallet_credit|loyalty_points|badge` (`:53`); approval step before payout
  (`:60`, `PerformanceCampaignService.php:29-40`); pays through `WalletService`.
- Metrics are a **fixed list**: `['bookings_completed_count','revenue_generated']`
  (`CampaignMetricResolver.php:26`), both computed from **`Booking` only** (`:34-52`;
  `field_worker` → `bookings.assigned_worker_id`). Parcel deliveries live in `ParcelOrder`, so
  **"10 deliveries" is not measurable today.**
- Verdict: threshold 10 + `wallet_credit` ₹200 + audience `field_worker` + approval is
  **already expressible**; the missing piece is one new metric key (e.g. parcel orders delivered)
  in `CampaignMetricResolver`. A small additive change there — not part of the coupon engine.
- Side note: `revenue_generated` sums `price_final` (gross). Under D1 it is unaffected by coupons.

---------------------------------------------------------------------------------------------

## 2. Data model

Principles: reuse `coupons`/`coupon_usages`/`bookings.coupon_id`; extend additively; **strings,
not enums, for anything that will grow** (CLAUDE.md step 5 widening-enum hazard); **no
per-target-type columns**; nothing nullable-by-default that would invent a business value
(null = not configured, 0 = waived).

### 2.1 Extend `coupons` (additive)

| Column | Type | Notes |
|---|---|---|
| `name` | string | admin label (code stays customer-facing) |
| `description` | text null | |
| `status` | string(20) default `draft` | `draft, active, paused, exhausted, expired` — string, not enum. `is_active` stays (kept in sync; legacy). |
| `module` | string(30) default `service` | module the coupon is *issued for*; also a target row (§4) |
| `campaign_id` | FK null → `coupon_campaigns` | attribution tag |
| `code_mode` | string(12) default `public` | `public` (one shared code) or `unique` (bulk, codes in `coupon_codes`; `coupons.code` is then an internal slug) |
| `total_budget` | decimal(12,2) null | null = uncapped (**not** invented) |
| `daily_budget` | decimal(12,2) null | null = uncapped |
| `reserved_amount` / `confirmed_amount` | decimal(12,2) default 0 | denormalised, only ever changed under the coupon row lock; reconciled against the usage rows by a check command |
| `usage_count_reserved` / `usage_count_confirmed` | uint default 0 | same |
| `per_phone_limit`, `per_device_limit` | uint null | null = off |
| `stackable_with_flash` | bool default false | |
| `auto_apply` | bool default false | |
| `funding_mode` | string(12) default `hq` | `hq`, `franchise`, `split`, `external` (§6) |
| `created_by`, `updated_by` | FK users null | |
| soft deletes | | match the catalog tables (`2026_09_30_000400_…`) |

Existing `franchise_id` keeps its meaning: owner franchise, null = HQ/global. `per_user_limit`
default 1 is pre-existing; the admin form will force an explicit value on create.

### 2.2 Extend `coupon_usages` → the redemption record (additive)

Chosen over a parallel `coupon_redemptions` table: same name flash sales' sibling already
follows, table presumed empty. Added: `status` string (`reserved, confirmed, released, consumed`),
`coupon_code_id` null, `booking_bundle_id` null, `original_amount`, `net_amount`,
`funding_split` json (per-fund amounts), `snapshot` json, `reserved_at/confirmed_at/released_at`,
`phone_hash`, `device_hash` null. `booking_id` becomes nullable (a bundle redemption is anchored on
the bundle; children carry their allocation on `bookings`). **Unique** on `(booking_id)` where not
null so one booking can never hold two redemptions ("one coupon per booking").
Release keeps the row (`status=released`) for audit instead of deleting it.

### 2.3 New tables

- **`coupon_targets`** — see §4. `(id, coupon_id, target_type, target_id null, operator[include|exclude], params json null)`, index `(coupon_id, target_type)`.
- **`coupon_campaigns`** — `(id, name, source string, notes, created_by, timestamps)`; `source` free string (influencer, flyer, instagram, …) so new channels need no migration.
- **`coupon_codes`** — bulk unique codes: `(id, coupon_id, code unique, status[available|redeemed|void], redeemed_by_user_id null, usage_id null, redeemed_at null, batch string)`. Single-use enforced by a conditional `UPDATE … WHERE status='available'` inside the reserve transaction + the unique `usage_id`.
- **`coupon_funds`**, **`coupon_fund_entries`**, **`coupon_fund_allocations`** — §6.
- **`franchise_coupon_limits`** — `(franchise_id unique, max_discount_percent null, max_budget null, max_active_coupons null)`. **All null by default = franchise may not create coupons** until Super Admin sets values (honours "I enter values").

### 2.4 Booking / bundle snapshot columns

`bookings`: `coupon_discount_amount decimal(10,2) default 0`, `coupon_snapshot` json null
(code, coupon id, type/value, caps, funding split, campaign, rule version, evaluated-at;
mirrors `cancellation_policy_snapshot`). `coupon_id` already exists.
`booking_bundles`: `coupon_id`, `coupon_discount_amount`, `coupon_snapshot`.
`coupon_discount_amount` on a child = that child's *allocated* share (§8).

Marketplace columns are left alone (§11).

---------------------------------------------------------------------------------------------

## 3. Pricing, money and commission design

### 3.1 D1 — `price_quoted` stays gross; one `amountPayable()`

```
gross        = price_quoted                         (unchanged meaning)
discount     = coupon_discount_amount               (0 when none)
amountPayable = gross − discount
```
All six charge sites (listed in §0.2) switch to `Booking::amountPayable()` /
`BookingBundle::amountPayable()`. Commission, payout, cancellation-fee basis, loyalty,
referral, `revenue_generated` keep reading `price_quoted`/`price_final` and are untouched.
Approved extras are **never discounted** (`price_final = price_quoted + extras` unchanged; the
discount only ever reduces the `price_quoted` portion). Discount is hard-capped at `price_quoted`
so `amountPayable ≥ 0`.

Position in the cascade (new step between 2/3 and 4 in §1.2, evaluated **after** the Prime step
so the engine knows whether an entitlement covered the booking):

```
resolvePrice → flash-sale layer → [entitlement adjustment] → COUPON → amountPayable
```
Because the entitlement step runs after `Booking::create`, the coupon reserve runs after `:218`
inside the same transaction, as a new `CouponService::reserve()` call; it receives
`flash_applied` and `entitlement_applied` flags from the cascade rather than guessing.

### 3.2 Stacking rules (all from your decisions)
- one coupon per booking / bundle (DB-unique);
- flash sale applied (`appliedSale != null`) ⇒ reject unless `coupons.stackable_with_flash`;
- entitlement applied/redeemed on the booking ⇒ **always reject** (no toggle);
- coupon never touches cancellation fees, visit fees, interim charges or extras: it only ever
  reduces the `price_quoted` portion.

### 3.3 Who pays — the fund is a cost allocation, not a cash movement
Online/wallet booking: customer pays `gross − d`; the platform then credits provider
`gross×(1−p−f)` and franchise `gross×f` exactly as today, so the platform's net is
`platform_commission − d`. That **gap `d` is the marketing cost**, recorded against the fund.
No wallet moves for the discount itself. (Provider payout is therefore provably identical.)

### 3.4 Cash bookings — SUPERSEDED (see §00: THUMB RULE — coupons are online-only, permanently; reimbursement design removed)
*Historical analysis, kept for the reasoning only:* provider collects `gross − d` cash but owes `platform+franchise` on gross
(`CommissionService.php:127-139`) ⇒ provider is short by `d`. Options considered:
- **A.** at completion credit the provider's wallet `d` from the funding source, own ref
  `booking:{id}:coupon-cash-reimbursement` through `WalletService` (commission code untouched;
  payout sweep nets it automatically);
- **B (recommended for C1–C3).** coupons are **not valid on `payment_method=cash`**; add A in C4
  when funds exist. Safe default, nothing to unwind.

### 3.5 Provider app/display
Provider job screens must keep showing the full price and (for cash, if A) the amount to collect.
`price_quoted` readers on provider surfaces are not changed; any "collect from customer" text
must read `amountPayable()`. To be enumerated in C1 (grep `Livewire/Provider/**`).

---------------------------------------------------------------------------------------------

## 4. Targeting rule structure

`coupon_targets(coupon_id, target_type, target_id, operator, params)`.
`target_type` is a **string registry key**, not an enum — adding a type is a code change only.

Initial registry (`config/coupon_targets.php` → class per type implementing `TargetRule`):

| target_type | scope | matches against |
|---|---|---|
| `city`, `zone`, `franchise`, `module` | context-level | context fields |
| `customer` (target_id = user id) | context-level | specific customers |
| `customer_type` (`params`: `{"type":"new"|"returning"|"prime"|"inactive","days":N}`) | context-level | derived customer facts |
| `service_category`, `service` | **line-level** | each line |
| `provider`, `hotel`, `product` | **line-level** | each line |
| `payment_method` | context-level | (used to implement §3.4 B) |

Evaluation (deterministic, documented, tested):
1. Per `target_type`: if the coupon has **any include** rows of that type, the context/line must
   match **at least one include**; **any matching exclude** rejects. No rows of a type = no
   constraint on that axis.
2. Context-level types gate the **whole coupon** (fail ⇒ ineligible with a reason code).
3. Line-level types compute the **eligible lines**; discount base = Σ eligible line totals;
   zero eligible lines ⇒ ineligible. *"All AC services except gas refill"* = `service_category`
   include AC + `service` exclude Gas-Refill.
4. `module` is always evaluated; a coupon with no module row defaults to `service`.
5. Targets are frozen into the snapshot (id lists), so later target edits never change a booking.

`customer_type` facts (centralised in `CustomerFacts`): **new** = zero non-cancelled bookings *and*
no `reserved|confirmed|consumed` coupon usage under the customer **or any user sharing the
verified phone**; **returning** = ≥1 completed; **prime** = active subscription on the Prime plan;
**inactive N** = no completed booking in N days. (Phone matching depends on users.phone
uniqueness — **UNVERIFIED**, checked in C1.)

---------------------------------------------------------------------------------------------

## 5. Promotion context contract

One DTO in, one result out; **modules own context building, the engine owns rules.**

```php
PromotionContext {
  module, franchise_id, city_id, zone_id, now,
  customer { id, phone_hash, device_hash, facts: CustomerFacts (lazy) },
  payment_method,
  lines[]  { line_ref, category_id, subcategory_id, service_id, product_id,
             provider_id, hotel_id, quantity, unit_price, line_total,
             flash_applied, entitlement_covered },
  subtotal,                       // Σ line_total, AFTER flash, BEFORE coupon
  code,                           // what the customer typed (nullable for auto-apply)
}
PromotionResult { eligible, reason_code, message, discount_total,
                  line_allocations[line_ref => amount], funding_split[fund_id => amount],
                  snapshot }
```
`CouponService` public surface: `validate(ctx)` (no state change), `suggest(ctx)` (auto-apply,
best eligible by discount), `reserve(ctx, booking|bundle)`, `confirm(usage)`, `release(usage, reason)`.
Services: `ServicePromotionContextBuilder` (single booking, cart, bundle). Parcel/hotel/food/
marketplace later add a builder only. City resolved via `franchise->city_id` as
`CancellationService` already does (`:64-67`); zone from the booking/address.

Reason codes are stable strings (`expired`, `not_started`, `inactive`, `exhausted`,
`over_per_user_limit`, `below_minimum`, `not_targeted`, `excluded`, `flash_sale_conflict`,
`entitlement_covered`, `payment_method`, `first_order_only`, `rate_limited`, …) so Flutter can
localise.

Percent coupons apply to the **eligible-line subtotal after flash**, capped by `max_discount`,
then by the remaining payable. `min_order_value` is tested against that same figure.

---------------------------------------------------------------------------------------------

## 6. Funds and ledger

### 6.1 Recommended: dedicated fund ledger (deviation flagged)
`WalletService` cannot express reservations or non-user accounts (§1.8). Faking funds as
system users would pollute `users` (queries such as `User::where('role','customer')`,
`CampaignMetricResolver.php:70`) and still could not hold. Recommended instead:

- **`coupon_funds`**: `(id, name, owner_type string, owner_id null, status)` —
  `owner_type ∈ hq | franchise | external` plus a free `owner_type`/`owner_id` pair, so a future
  hotel/vendor/brand funder is **a row, not a migration**.
- **`coupon_fund_entries`** (append-only; update/delete blocked like `ActivityLog`):
  `(id, fund_id, entry_type[contribution|adjustment|reservation|release|debit], amount,
  coupon_id null, usage_id null, booking_id null, reason, idempotency_key UNIQUE, actor_id, created_at)`.
  - `available = Σcontribution + Σadjustment − Σdebit − open reservations`
    (open = reservation not yet matched by a release/debit on the same `usage_id`).
  - All writes happen under a `lockForUpdate()` on the `coupon_funds` row — same pattern as
    `FlashSaleService::redeem()` — so two last-rupee redemptions cannot both pass.
- **`coupon_fund_allocations`**: `(coupon_id, fund_id, percent)`; percents must sum to 100
  (validated on save and re-checked at reserve). Split amounts use largest-remainder rounding so
  Σ parts = discount to the paisa.

### 6.2 Where real money *does* move (stays on `WalletService`)
- **Franchise marketing fee → fund**: a `contribute` action debits the franchise owner's wallet via
  `WalletService::debit()` and writes the `contribution` entry in the **same transaction**, with
  a shared ref/idempotency key. The fee amount and collection method are business values you set
  later; the engine ships with no default (null = no automatic collection).
- **HQ fund**: contributions/adjustments by Super Admin only, mandatory reason, audit-logged.
- **Cash reimbursement (option A, §3.4)**: provider credit through `WalletService::credit()`.
- The reserve/confirm/release/debit entries themselves are **cost-allocation records, not cash
  movements** (the customer simply paid less). This is the one place the design is *not*
  "through the wallet service" — **needs your explicit approval (Q3).**

### 6.3 Lifecycle against the fund
`reserve` (booking creation) → `reservation` entry · `confirm` (completion) → `debit` entry
replaces the reservation · `release` (cancel before work) → `release` entry. Each carries a
deterministic idempotency key (`coupon:{usage_id}:{reserve|confirm|release}:{fund_id}`), so a
retried job cannot double-debit (test required).

### 6.4 Exhaustion and auto-pause
Inside the reserve transaction, after counters update: if `confirmed+reserved ≥ total_budget`
**or** any funding fund's `available` < next minimum redemption ⇒ `status=exhausted` and an
`AdminOpsAlertService` push (existing, `CreateBookingAction.php:98`) + a notification to the
coupon owner. **Daily cap** is not a pause: it rejects with `daily_cap_reached` until the next
local midnight (via `TimezoneResolver`). `exhausted` is distinct from admin `paused`, so a
top-up can re-activate automatically only if the admin enabled "resume when funded".

---------------------------------------------------------------------------------------------

## 7. Redemption lifecycle and locking

```
VALIDATE  (read-only, rate-limited)         → result + reason code, nothing written
RESERVE   (inside createWithinTransaction)  → lock coupon row (+ fund rows, +coupon_codes row),
                                              recount usage/budget under lock, write usage(status=reserved),
                                              fund reservation entries, booking.coupon_discount_amount + snapshot
CONFIRM   (CompleteBookingAction, after commit, own tx, idempotent) → usage=confirmed, reservation→debit
RELEASE   (cancel before any work)          → usage=released, counters decremented, reservation→release
CONSUME   (cancel AFTER work started)       → usage=consumed (counts toward limits), reservation released, no fund debit
```
- **Locking:** `Coupon::lockForUpdate()` is the serialisation point for global/per-user/per-phone/
  per-device/budget checks; `coupon_codes` single-use by conditional update; `UNIQUE(booking_id)`
  on the usage as the final backstop. Order of locks is fixed (coupon → codes → funds by id) to
  avoid deadlocks.
- "**Before any work**" definition (Q5): no provider arrival verified and no interim charge —
  i.e. the same gate the new cancellation policy already uses (`arrival_verified_at`,
  `InterimChargeCalculator`). Cancelled after that ⇒ `consumed`, discount forfeited (the
  customer's refund is `paid − charge`, per existing policy; the discount is not re-credited).
- **Frozen snapshot** on booking (and bundle) at reserve; nothing downstream reads the live coupon.
- **Wallet-paid:** the debit in `payWithWallet` uses `amountPayable()`; reserve happens first in
  the same transaction so an insufficient wallet rolls back the reservation too.
- **Online:** Razorpay order amount = `amountPayable()`; webhook capture must compare against it
  (**UNVERIFIED** whether the webhook asserts the amount — check in C1).

---------------------------------------------------------------------------------------------

## 8. Bundles

One coupon per bundle, evaluated against the **bundle subtotal** (`CreateBookingBundleAction.php:139-144`).
Discount `D` is allocated to children proportional to each child's eligible `price_quoted`, using
**largest-remainder rounding** so `Σ child_discount = D` exactly; stored in
`bookings.coupon_discount_amount`; `booking_bundles.coupon_discount_amount = D`; bundle payment
amount = `total_price_quoted − D`.

`BundleSettlementService::refundDue()` change (C1, small and tested): retained per child becomes
`cancelled ? cancellation_fee : (price_quoted − child_coupon_discount)`. Without this the
discounted payment would be compared against gross and over-refund/under-retain. Cancelling one
child releases/consumes **only that child's share** of the usage (the usage is bundle-level; the
fund reservation is split per child via the stored allocation).

---------------------------------------------------------------------------------------------

## 9. Admin screens and permissions

Livewire, following `FlashSales\Manage` / `PerformanceCampaigns\Manage` conventions
(layout must be named — memory `cancellation-policy-deployed`).

Permissions (seeded by migration in C2, group "Marketing"):
`coupons.view`, `coupons.manage`, `coupons.approve` (activate a coupon above a threshold or
change funding), `coupon_funds.view`, `coupon_funds.manage` (contributions/adjustments),
`coupons.manage_own_franchise`, `coupon_reports.view`.
- **Super Admin:** all, any scope, any fund.
- **Franchise admin (`franchise_owner`):** `coupons.manage_own_franchise`, scope enforced by
  `AuthorizationService::scopeCovers()`; forced `franchise_id` = own, `funding_mode=franchise`
  with only their own fund; blocked by `franchise_coupon_limits` (max discount %, max budget, max
  active coupons) — **null limits = cannot create**.
- **Marketing role:** just a custom role with chosen `coupons.*` slugs; nothing hard-coded.

Screens: coupon list/filter · create-edit wizard (basics, discount, limits, validity, targeting
builder with include/exclude chips, funding split, budget caps, stacking toggles, auto-apply) ·
pause/resume/archive · bulk-code generator + CSV export (C5) · funds (balance, reserved,
contributions, per-coupon spend) · per-franchise limits · audit pane per coupon (reuses the
`CancellationPolicy\Manage.php:279` approach).

**Audit:** every create/update/status/limit/funding/target change writes
`ActivityLogger::logModel($actor, $coupon, 'coupon.updated', ['changes'=>[field=>[old,new]]])`
to the append-only `activity_log` → who, what, old, new, when. Fund entries are themselves
append-only. Feature settings (kill-switch `coupons.enabled`, abuse thresholds, validate rate
limit, alert recipients) use the existing `Setting` cascade with the `EarningsSettings`
"null = OFF" rule (`CompleteBookingAction.php:131-143` shows the usage).

---------------------------------------------------------------------------------------------

## 10. Customer UI and API

- **API (Flutter):**
  - `POST /api/coupons/validate` — auth, `throttle:` limiter keyed by user+IP; body `{code, context}`
    where context is *ids only* (service ids, address id, quantities) — the server builds prices.
    Returns reason code, discount preview, net payable. **Never trusts an amount.**
  - `GET /api/coupons/suggestions` — auto-apply candidates (C5).
  - `POST /api/bookings` and `/api/booking-bundles` accept `coupon_code`; any client
    `discount`/`price` field is ignored (pricing-authority contract, mirrored by a new test).
    `BookingResource`/`BookingBundleResource` expose gross, discount, payable, coupon code.
  - Bundle idempotency fingerprint includes the code.
- **Web (Livewire):** "Have a coupon?" in `Customer\Booking\Wizard` (review step), cart
  (`ServiceCartService`) and `Customer\Checkout`; applied-coupon chip with remove; totals show
  gross / coupon / payable; order page + invoice show the frozen snapshot.
- Errors are reason-code driven; no message ever reveals other customers' eligibility facts.

---------------------------------------------------------------------------------------------

## 11. Reporting (C6)

Dimensions: coupon, campaign, franchise, fund, date. Measures: redemptions (reserved/confirmed/
released/consumed), discount spent, bookings and revenue (gross) generated, **new vs returning**
customers (new = first completed booking was this redemption), **90-day repeat bookings** of
acquired customers, **cost per booking**, **cost per new customer**, fund balance/contributions/
spend/remaining budget. Built from `coupon_usages` + `bookings` + `coupon_fund_entries` — read
models/queries, no new write paths. Franchise owners are scoped by `scopeCovers`; they see only
their franchise's coupons, fund and customers. Attribution uses `campaign_id`/`source`.

### Marketplace reconciliation (report only, no change now)
`marketplace_orders.coupon_code` / `coupon_discount_amount` (`…007000:67-68`) are unwired. When
Marketplace is onboarded: a `MarketplacePromotionContextBuilder` calls the same `CouponService`;
the order stores `coupon_id`, snapshot and the discount; the existing two columns are kept as the
display copy. **Same rule as services:** `total_amount` must remain the pre-coupon amount that
`CommissionService::applyForMarketplaceOrder` splits (`:285-295`, `price_final ?? total_amount`),
with the payable computed separately. Decision for later: marketplace per-item coupon + per-store
commission interplay.

---------------------------------------------------------------------------------------------

## 12. Phased build plan

Each phase = its own branch/commit set with explicit paths, migrations shown (`up()`/`down()`)
for approval **before** being written, full-suite run with pre-existing failures separated,
screenshots where UI exists. Prod stays behind `coupons.enabled = null (OFF)` until C4 lands, so
no coupon can be redeemed without a fund ledger behind it.

| Phase | Delivers | Migrations (shown first) |
|---|---|---|
| **C1 — Core engine (services)** | `CouponService`, `PromotionContext`/`Result`, target registry, `ServicePromotionContextBuilder`, validate/reserve/confirm/release/consume, pricing layer after the entitlement step, `amountPayable()` at the six charge sites, bundle allocation + `refundDue` fix, booking/bundle snapshot, invoice discount line, `coupons.enabled` kill-switch, audit logging service. Cash excluded via `payment_method` rule. | extend `coupons`, extend `coupon_usages`, `coupon_targets`, `bookings`+`booking_bundles` snapshot cols |
| **C2 — Super Admin screens** | list/create/edit/pause, targeting builder, limits, caps, funding split (config only, enforcement in C4), audit pane, permissions. | permissions seed, `coupon_campaigns` |
| **C3 — Customer entry** | wizard/cart/checkout UI, `validate` endpoint, `coupon_code` on booking APIs, resources. | none |
| **C4 — Funds** | funds + ledger + allocations, contributions via `WalletService`, reserve/confirm/release entries, exhaustion auto-pause + alerts, franchise limits and franchise-admin permissions, cash reimbursement (A) if approved. **Prod can be enabled after this.** | `coupon_funds`, `coupon_fund_entries`, `coupon_fund_allocations`, `franchise_coupon_limits` |
| **C5 — Bulk + abuse** | `coupon_codes`, generate N + CSV export, auto-apply/suggestions, per-phone/per-device limits, first-order history check, validate rate limits. | `coupon_codes`, device-hash capture column(s) |
| **C6 — Reporting** | dashboards per coupon/campaign/franchise/fund, franchise-scoped. | none (indexes only if needed) |

### Test plan (maps to your §9 list, added to the phase noted)
C1: client-sent discount/price ignored (new `CouponPricingAuthorityTest` mirroring
`PricingAuthorityTest`); each target type include+exclude; expired/inactive/over-limit/below-minimum;
concurrent last redemption; **payout and commission byte-identical with vs without coupon**;
flash stacking off/on; entitlement-covered blocked; cancellation/visit/interim fees untouched;
cancel-before-work releases; completion confirms once, idempotently; bundle allocation sums exactly
and per-child refund correct; coupon edit never changes an existing booking; existing Pricing,
FlashSales, Prime, Cancellation, Wallet suites unchanged. C4: budget cap and fund exhaustion
auto-pause; franchise admin cannot create outside franchise/limits. C5: bulk codes single-use.

**Concurrency caveat:** on SQLite `lockForUpdate()` does nothing, so the "last redemption" test
in CI proves the *logic* (second reserve sees the first's counters, DB unique constraints reject
duplicates) but not row-lock behaviour. Proposed: a MySQL-only test group run manually against
the local MySQL, plus the `UNIQUE` backstops, so the guarantee does not rest on the lock alone.

---------------------------------------------------------------------------------------------

## 13. Risks and conflicts with existing code

1. **Charge-site sprawl (high).** Six places read `price_quoted` as the charge (§0.2). Missing one
   = customer charged full price or provider/platform mismatch. Mitigation: one accessor + a test
   that greps nothing but asserts payable at each path (wallet, Razorpay order, bundle).
2. **Entitlement ordering.** `resolveAndConsumeForBooking` consumes as it resolves
   (`EntitlementService.php:40-64`) — no preview. `validate()` therefore cannot know if Prime will
   cover a booking without either a read-only resolver (a new method on `EntitlementService`) or
   a conservative rule. Plan: add `previewCoverage()` read-only in C1; the authoritative check
   still happens at reserve.
3. **Loyalty/referral on gross.** Points are earned on `price_final` (`CompleteBookingAction.php:135`)
   — customers earn points on the discounted-away portion. Left as is by D1 (you said payout/commission
   untouched; loyalty not covered) — **Q7**.
4. **Cancellation fee basis is gross** (`CancellationService.php:70`). With a coupon the fee could
   exceed what the customer paid; `refundIfPaid` already floors the refund at 0 (`:226-234`) but the
   customer-facing quote must show it clearly. Recommended: keep gross, cap refund at 0 — **Q6**.
5. **Prod unknowns:** coupon tables' row counts; users.phone uniqueness; whether Razorpay webhook
   asserts amount; MySQL vs SQLite parity for locks. All to be checked at the start of C1.
6. **`per_user_limit` default 1** is pre-existing and would silently apply to old rows — admin form
   will require it explicitly; the migration will not alter the default.
7. **Pre-existing**: repo is not pint-clean — only new files will be formatted; plaintext prod DB
   password in `scripts/backup-database.sh` (memory) is unrelated but still open.
8. **No device identifier exists today** on bookings/users (only `request_fingerprint` for bundle
   idempotency). Per-device limits need a new client-sent device id, which is spoofable — treat as
   a soft signal; per-phone/per-user remain the hard limits.

---------------------------------------------------------------------------------------------

## 14. Open questions (need your decision before C1)

| # | Question | My recommendation |
|---|---|---|
| Q1 | **D1** — keep `price_quoted` gross + new discount column + `amountPayable()` (§3.1)? | Yes. Only way payout/commission stay provably identical. |
| Q2 | Cash bookings with coupons (§3.4): exclude now, or reimburse provider from fund? | Exclude in C1–C3; add reimbursement (A) in C4. |
| Q3 | Fund as its own append-only ledger, with real money only moving through `WalletService` at contribution time (§6)? | Yes. Wallet cannot hold/reserve. |
| Q4 | Extend `coupon_usages` instead of a new redemptions table (§2.2)? | Yes, if prod count is 0 (you run the COUNT). |
| Q5 | "Before any work" = no arrival verified + no interim charge; after that the usage is `consumed` and the discount forfeited? | Yes; reuses the cancellation policy's own gate. |
| Q6 | Cancellation fee basis stays gross; refund = `paid − fee`, floored at 0? | Yes (policy unchanged). |
| Q7 | Loyalty points: earn on gross (today) or on amount paid? | Amount paid is fairer, but it changes earnings logic — your call, not in scope unless you say so. |
| Q8 | Does a Prime *percentage* pricing entitlement count as "covered by membership entitlement" (blocks coupon), or only quantity redemptions? | Any applied/consumed entitlement blocks (simplest, matches your wording). |
| Q9 | Daily cap behaviour: reject until next local midnight (not a pause)? | Yes. |
| Q10 | Hold prod `coupons.enabled` OFF until C4 is deployed? | Yes. |

*(All of Q1–Q10 are now answered — see §00. New open item: Q11.)*

**Out of scope, confirmed:** rider/delivery challenges (§1.9 report only), marketplace changes,
GST treatment (questions for your CA listed in §1.6).

---------------------------------------------------------------------------------------------

## 6.5 Fund reconciliation report (C4, per Q3)
Read-only report, Super Admin + franchise-scoped. For a date range and fund it shows:
`Σ fund contribution entries` vs the matching `WalletService` debits (refs `coupon-fund:{fund}:contribution:*`);
`Σ debit entries` vs `Σ coupons.confirmed_amount` vs `Σ coupon_usages.net discount WHERE status=confirmed`;
open `reservation` entries vs `usage.status=reserved` rows; and a list of any usage whose booking is
completed but has no debit entry (or vice versa). Any non-zero difference is a red row. It is a
detector, not a fixer: corrections are explicit, reasoned `adjustment` entries.

---------------------------------------------------------------------------------------------

## 15. Questions for the CA (send as-is)

> **Context.** 1CallFix is an online services marketplace. Customers book a home-service
> professional through our platform and pay us online (Razorpay or wallet). We keep a
> platform commission and pay the rest to the professional (and a share to the franchise
> partner). We want to offer promotional coupons. The company (or a franchise's marketing
> fund) bears the cost of the discount; the professional is paid on the **full, undiscounted**
> price. Our invoices today show a single line with the amount the customer paid; they do
> not currently show a taxable value or GST breakup.
>
> **Invoice presentation**
> 1. When a coupon is used, how should the invoice show it — as a separate discount line
>    (gross price, coupon, net payable), or only the net amount paid?
> 2. Should the coupon code and who funded it (HQ / franchise / third party) appear on the invoice?
>
> **Taxable value**
> 3. Does a company-funded coupon reduce the taxable value of the service? Does the answer
>    change if the discount is funded by (a) the company, (b) a franchise's marketing fund,
>    (c) a third-party partner (hotel/brand)?
> 4. Are we the supplier of the service or an agent/intermediary for the professional? Does
>    that change who issues the invoice and on what value the discount is applied?
> 5. If GST is later shown on invoices, on which amount is it computed — the pre-discount
>    price or the amount actually paid — and which document carries the pre-discount value?
>
> **Platform commission and franchise share**
> 6. Our commission and franchise share are calculated on the pre-coupon price while the
>    customer pays less. Is the commission invoice to the professional/franchise affected?
>
> **Promotional / referral credit**
> 7. When promotional or referral credit (non-withdrawable, usable only toward online
>    booking payments) is granted, is that a taxable event, or only when it is used?
> 8. When it is used to pay for a booking, is the credit treated as a discount (reducing
>    value) or as consideration received (payment)? Does an expired, unused credit have any
>    tax or accounting treatment?
> 9. How should the cost of coupons and credits be booked in our accounts (marketing
>    expense / contra-revenue), and when is it recognised — at booking, at completion, or at expiry?
>
> **Refunds and cancellations**
> 10. On a cancelled coupon booking where we refund only the amount actually paid (and keep a
>     cancellation fee calculated on the full price), how should the credit note and fee
>     invoice be raised?

*Engineering note: the engine stores gross, discount, net and the funding source per booking in an
immutable snapshot, so any of the above treatments can be produced without re-computation or data loss.*

---------------------------------------------------------------------------------------------

## 16. Concurrency (lock) test — manual MySQL procedure

No local MySQL exists on the dev machine (§00.4); CI/phpunit uses SQLite, where `lockForUpdate()` is a
no-op. SQLite tests prove the *logic* (a second reserve sees the first's counters; the DB `UNIQUE`
constraints reject duplicates). The *lock* must be proven on MySQL/InnoDB:

1. On a **non-production** MySQL (a scratch schema, e.g. local XAMPP/Docker `mysql:8`), set `DB_CONNECTION=mysql`
   in a throwaway `.env.mysqltest`, run `php artisan migrate --env=mysqltest`, seed one coupon with
   `usage_limit = 1`, `per_user_limit = 1`, `total_budget = 500`, discount 100.
2. Two terminals (or `xargs -P2`), each running the same artisan test command that calls
   `CouponService::reserve()` for two **different** customers at the same instant (a barrier file
   or `SELECT SLEEP(2)` inside the first transaction after the lock makes the overlap deterministic).
3. **Expected:** exactly one `coupon_usages` row `reserved`; the other call throws `exhausted`;
   `coupons.reserved_amount = 100`, `usage_count_reserved = 1`. Repeat 50× — never 2.
4. Repeat for: two reserves by the **same** customer (per-user limit), the **last rupee of budget**,
   and a `coupon_codes` single-use code (`UPDATE … WHERE status='available'` affected-rows = 1).
5. Deadlock check: reserve a 2-fund split coupon from two sessions whose funds are listed in opposite
   order — fixed lock order (coupon → codes → funds by id) must prevent a deadlock; any
   `Deadlock found` is a failure.
6. Record the raw output in the phase report. This test is tagged `@group mysql` and excluded from the
   default suite.

---

*STOP. No code or migration has been written. Q1–Q10 are answered (§00); one new question (Q11) and the
promotional-credit design (`docs/PROMOTIONAL_CREDIT_DESIGN.md`) await your approval before C1.*
