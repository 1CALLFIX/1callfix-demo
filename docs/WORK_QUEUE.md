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

| # | Item | Status |
|---|---|---|
| 1 | fee_waiver fix + visit-charge thumb rule + owner decisions (minimum labour charge, no cash waiver, strict unit) | MERGED to main 2026-10-04 (see Done); awaiting deploy by owner |
| 2 | 0b — SUPER ADMIN PLACEHOLDER FIXES | not started |
| 3 | O1–O6 — promotional credit open decisions | not started |
| 4 | Admin email-change verification link (before franchise/finance admins exist) | not started |
| 5 | Promotional credit split + combined wallet/Razorpay + wallet_breakdown + payout leak fix | not started |
| 6 | EARN4 referrals updated for promo credit and the thumb rule | not started |
| 7 | Coupon engine C1–C3, then optional HQ pilot on the owner's go-ahead, then C4–C6 | C1 BUILT + committed (daf58fd, feature/coupon-engine-c1-c3, not merged); C2, C3 not started |
| 8 | Re-add the classes in docs/PENDING_FRONTEND_BUILD_ITEMS.md in the next change that needs a front-end build | not started |

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
