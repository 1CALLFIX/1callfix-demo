# 1CallFix work queue

**Read this file at the start of every session. Update it after every step.**
It exists so context is never lost between sessions. The owner sets the order; the
standing rules in `CLAUDE.md` (deploy rule, thumb rule, manual-money approval model) apply to
every item. Stop for the owner's review after each step. Merge, push and deploy only when the
owner says; the owner runs every server step.

Standing rules for all items:

- No migration without the owner's approval. Show up() and down() first.
- No changes on production. Never save a prod setting.
- Do not invent business values. Build settings; the owner enters values. Null = not configured
  (fail closed), zero = explicitly waived.
- Every feature fully controllable from Super Admin, audit-logged.
- Thumb rule (CLAUDE.md): online payment only for all benefits.
- All money through existing wallet/ledger/gateway services, idempotent, row-locked where concurrent.
- Separate commits per workstream, explicit paths, no untracked files. Raw output and file/line citations.

## Decisions on record

1. "Free Service Visit" (`fee_waiver`): limit it to the visit/inspection charge only, as its label
   says. It must never zero the service price. The 4 included-service entitlements stay as they are.
   Report every place `fee_waiver` is applied (file/line), fix, and test: `fee_waiver` removes only
   the visit charge; full service price still charged; included-service entitlements unchanged.
   Prod has 0 Prime subscribers, so no data fix needed; confirm by code reading only.
2. Email verification link for admin email change: YES, but later. Add to the queue before any
   franchise or finance admin accounts are created.

## Queue (in order)

Owner order of 2026-10-04 (supersedes the table below where they differ): **A2 -> A3 -> B -> C -> D -> E -> F -> G -> H**.

| Step | Item | Status |
|---|---|---|
| A2 | Mid-work cancellation final rule: one declared amount, one cap (`cancellation.interim_cap_percent`), no floor, post-payment dispute | DEPLOYED + verified by owner 2026-10-04 (main `52ce888`; migration ran, build copied, cap set to 40) |
| A3 | Small fixes to A2: rollback guards on both migrations, dispute refund destination (original method / wallet), who bears the refund (provider / company / split) with provider recovery via ledger + `provider_dispute_debts`, dispute escalation alerts (push + email), design doc updated | BUILT + merged to main (see section A3); awaiting owner deploy |
| B | Rebase `feature/coupon-engine-c1-c3` on the new main, resolve the `EntitlementService` conflict, re-run related suites, do NOT merge | not started |
| C | Coupons C2: Super Admin screens (create/edit/pause, targeting, limits, budget cap, daily cap with migration shown first, stackable toggle, HQ funding, campaign tag, live usage, `coupons.enabled` + `coupons.unpaid_hold_minutes` switches, `coupons.manage` permission, audit log) | not started |
| D | Coupons C3: customer coupon field (wizard, cart, checkout, bundles), validate endpoint + API, full price/discount/payable, Razorpay or wallet-only (combined later), cash rejected, clear errors, rate limit, unpaid-hold countdown | not started |
| E | Full suite, stop for review, merge on owner approval. Coupons stay OFF in prod until the owner switches them on. Give the pilot setup steps | not started |
| F | Ad readiness: UTM capture, Open Graph + canonical, clean category slugs with 301 redirects, commit the SEO baseline doc, AAAA check | not started |
| G | Glover app access-log investigation (read-only) | not started |
| H | Remaining queue below (items 2-6, 8) | not started |

Earlier table (kept for reference):

| # | Item | Status |
|---|---|---|
| 1 | fee_waiver fix + visit-charge thumb rule + owner decisions (minimum labour charge, no cash waiver, strict unit) | MERGED to main 2026-10-04 (see Done); minimum labour charge REMOVED by A2 |
| 2 | 0b — SUPER ADMIN PLACEHOLDER FIXES | not started |
| 3 | O1–O6 — promotional credit open decisions | not started |
| 4 | Admin email-change verification link (before franchise/finance admins exist) | not started |
| 5 | Promotional credit split + combined wallet/Razorpay + wallet_breakdown + payout leak fix | not started |
| 6 | EARN4 referrals updated for promo credit and the thumb rule | not started |
| 7 | Coupon engine C1–C3, then optional HQ pilot on the owner's go-ahead, then C4–C6 | C1 BUILT + committed (daf58fd, feature/coupon-engine-c1-c3, not merged); C2, C3 not started (steps B-E above) |
| 8 | Re-add the classes in docs/PENDING_FRONTEND_BUILD_ITEMS.md in the next change that needs a front-end build | not started |

### A3. Small fixes to A2 (2026-10-04)

Migration `2026_10_04_200000_a3_dispute_refund_destination_bearer_and_rollback_guard` (approved up()/down() shown first;
`provider_dispute_debts` uses `restrictOnDelete`); the A2 migration's own `down()` also refuses while data exists.
Built: refund destination (online -> original Razorpay method by default via the existing gateway refund, partial, capped at
what is still refundable; wallet-paid and cash -> wallet; admin may choose wallet for an online payment only with a recorded
customer-agreed note), who bears the refund (admin must choose provider / company / split; shares add up exactly in paise),
provider share debited through `WalletService` (ref `booking:{id}:dispute-share:{dispute}`) with any shortfall in
`provider_dispute_debts`, swept at payout-request time (`PayoutService::settleDisputeDebts`, blocks withdrawal like the
cash-commission debt, no franchise share), provider Earnings card + Request Payout line with the dispute reference, escalation
alerts by push + email once per level (`AdminOpsAlertService::disputeRefundEscalation`, type `dispute_refund_escalation` on the
/admin/alert-emails switches), `docs/CANCELLATION_POLICY_DESIGN.md` brought to the current rule.
Notes: a cash booking's "amount paid" is `price_final` (no gateway payment exists); there is one wallet per user (no separate cash
balance), so cash-booking refunds credit that wallet; prod needs the migration, then (optionally) the `refund.dispute.*` limits.

### A2. Mid-work cancellation — final rule (2026-10-04)

Rule (also in CLAUDE.md): visit charge only when the provider arrived and NO work was done. Work started then
stopped: the provider enters ONE amount for the work done (labour + parts together); the customer pays it; it can
never exceed `cancellation.interim_cap_percent` % of the booking total (price_quoted + approved extras). Null cap =
provider cannot submit (fail closed). The owner will enter 40 in Super Admin -> Cancellation Policy. No floor.

Built: `InterimChargeCalculator` (single cap, no floor, no visit charge once work started), `SparesDeclaration`
(`work_amount`, cap check, cumulative), `PolicySettings` (cap default null, `interim_min_labour` removed),
`CancellationPolicy::INTERIM_TEXT` (one shared customer sentence), provider web + API field `work_amount`, snapshot
of the cap per booking (already in `PolicySettings::snapshot`), post-payment `BookingDispute` + `BookingDisputeService`
(approval model: permission `bookings.refund_dispute`, scope, limits, maker-checker, escalation, idempotent wallet
credit), admin queue `/admin/booking-disputes`, limits in Refund Controls, customer "Think the price is wrong?" card.
Migration `2026_10_04_120000_add_interim_amount_and_booking_disputes` (additive: `bookings.interim_amount`,
`booking_disputes`, permission row). Prod must enter the cap (40) BEFORE providers can declare an amount.
Open: escalation records a level + audit row only (no email/push alert yet); dispute refunds credit the wallet
(never the original gateway); legacy `interim_progress_percent` / `interim_parts_cost` columns kept, no longer written.

### 1. fee_waiver fix

Status: built and committed on `feature/fee-waiver-visit-only`; awaiting owner review. Findings and
owner questions are in the session report (summary: the only place `fee_waiver` touches a price is
`EntitlementService`; the seeded Prime Silver "Free Service Visit" uses `service_completed` and is
never reached by it; cash bookings now get no waiver; percent/fixed/member_price discounts still
apply to cash bookings, which the thumb rule forbids; open owner decision).

See decision 1. Report every place `fee_waiver` is applied (file/line), fix, and test:
`fee_waiver` removes only the visit charge; full service price still charged; included-service
entitlements unchanged. Prod has 0 Prime subscribers, so no data fix needed; confirm by code
reading only.

Step 3 outcome (2026-10-04): CLAUDE.md now carries the VISIT/INSPECTION CHARGE ONLY WHEN NO WORK IS DONE rule. The earlier
fix (614e663: price 500 -> 351) was itself wrong under that rule and is superseded by 38fc371: fee_waiver never touches
a booking price; PrimeWaiver forgives the charge and uses one unit only on a no-work cancel after verified arrival
(never for cash). Open owner decision: `InterimChargeCalculator` floors the mid-work cancellation labour at the visit
charge (`max(visit, progress)`) even though some work was done.

### 2. 0b — SUPER ADMIN PLACEHOLDER FIXES

a. Loyalty points earned only when the booking customer has role='customer'. Staff and provider
   accounts placing test bookings earn nothing. (CompleteBookingAction.php:131-137)
b. Daily digest: skip recipients whose phone matches 0000000000_% before any WhatsApp send; log a
   warning naming the account.
c. Do not reverse or delete existing loyalty points. Report only.
d. "Disable admin account" action: Super Admin only, cannot disable yourself, cannot disable the
   last active Super Admin, no delete, reason required, audit-logged, disabled account cannot log in
   and its sessions end. The owner will choose which super_admin account to disable.
Tests for each.

### 3. O1–O6 — promotional credit open decisions

From docs/PROMOTIONAL_CREDIT_DESIGN.md. O7 decided: combined wallet + Razorpay. Already decided:
promo first then cash; payments.wallet_breakdown approved in principle; PayoutService pays out only
the cash bucket; thumb rule applies. Paste O1–O6 with a recommendation each; build nothing.

### 4. Admin email-change verification link

A verification link to the new address before the login email switches. Must land before any
franchise or finance admin accounts are created.

### 5. Promotional credit split

Promotional credit split + combined wallet/Razorpay + wallet_breakdown + payout leak fix.

### 6. EARN4 referrals

EARN4 referrals updated for promo credit and the thumb rule.

### 7. Coupon engine

Coupon engine C1–C3 (docs/COUPON_ENGINE_DESIGN.md, decisions at the top), then optional HQ pilot on
the owner's go-ahead, then C4–C6.

**C1 built ahead of the queue (2026-10-04)** on `feature/coupon-engine-c1-c3` (commit `daf58fd`, off main `9be16e5`),
committed, NOT merged; coupons stay OFF in production (`coupons.enabled` and `coupons.unpaid_hold_minutes` unset).
Engine, pricing (`amountPayable()`), thumb-rule enforcement, dispatch gate + unpaid-hold sweep, bundles,
invoice line, audit service, 40 tests. Open items carried forward:

- Daily cap (Q9): no column approved, not built.
- Combined wallet + Razorpay: waits for the promotional-credit step (item 5).
- Loyalty-on-amount-paid test only pins the arithmetic, not `CompleteBookingAction` end to end.
- Expected merge conflict in `EntitlementService.php` with `feature/fee-waiver-visit-only` (C1 extracted
  `pricedByEntitlement()` and added `previewBestPricingEntitlement()`); resolve in the rebase step.
- Bundle: a child already priced by a member benefit is excluded from the bundle coupon (not larger-of).
- Still to do: C2 admin screens, C3 customer entry + API (`coupon_code` on booking APIs, bundle fingerprint).

### 8. Pending front-end classes

Re-add the classes in docs/PENDING_FRONTEND_BUILD_ITEMS.md in the next change that needs a
front-end build.

## Ad launch proposals (audit 2026-10-04, proposals only — nothing built)

| Tag | Proposal |
|---|---|
| AD-BLOCKER — DONE 2026-10-04 | Homepage: `1callfix.com` and `www` serve the new Laravel site (public GETs over http/https, browser/Googlebot/curl all return the new page; the old Glover page seen earlier was a stale external copy). |
| AD-BLOCKER | Confirm the 26 Sep docRoot switch did not break the Glover mobile app (`com.call.customer`) that calls `1callfix.com/api/*` (risk R1 in PHASE_SEO_DISCOVERY_AND_MIGRATION_BASELINE.md). |
| AD-BLOCKER | Ad landing URL: a category page `/categories/{slug}` (slug-based) or `/services/{id}`; neither shows the visit charge, the booking wizard review does. Reword that text to the Step 3 thumb rule (charge applies only when no work is done). |
| AD-BLOCKER | UTM/gclid capture: nothing captures it today. Smallest change = a middleware that stores first-touch utm_*/gclid/fbclid in session + a 30-day cookie, copied at booking creation into one new nullable JSON column `bookings.acquisition` (migration needs owner approval; up()/down() to be shown first). |
| LATER | SEO layer: canonical URL, Open Graph/Twitter tags, JSON-LD (LocalBusiness + Service), alt text on catalog images, slug-based service URLs (`services.slug` is not unique today). |
| LATER | Commit PHASE_SEO_DISCOVERY_AND_MIGRATION_BASELINE.md under docs/ with a "dated 2026-09-25, partly superseded" header (sitemap.xml/robots.txt were built after it). |
| LATER | Root-domain cutover items from that report: HTTPS + www→apex 301, `assetlinks.json` ownership, Glover `/api/*` collision decision. |

## Done (for context)

| Item | Where |
|---|---|
| Step 0: Razorpay captured-amount check | merged, main `e2a3d4a` |
| My account page (name/email/phone/password) | merged, main `9a98079` |
| 0c: mismatch refund approval model (permission, scope, limits, maker-checker, queue, escalation, reject, notices) | merged, main `72f91cb`; deployed; Refund Controls values still to be entered by the owner |
| 0d: critical admin alerts by email | merged, main `d9f3556` |
| 0e: My account follow-up (sign out other sessions, old-email notice, confirm new email) | merged, main `d9f3556` |
| One visit charge setting + launch-price display | merged, main `9be16e5`; owner deploying |
| MANUAL MONEY ACTIONS approval model recorded in CLAUDE.md | main `60bb1fa` |

## Facts to remember

- `schedule:run` cron runs every minute on prod (owner-confirmed 2026-10-03).
- Prod has 0 Prime Silver subscribers (owner, 2026-10-03).
- Full suite takes ~17 min and can be killed for memory; run it in directory chunks. The one known
  failing test is `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default`.
