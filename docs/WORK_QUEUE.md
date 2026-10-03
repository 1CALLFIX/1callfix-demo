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
| 1 | fee_waiver fix (decision 1 above) | IN PROGRESS |
| 2 | 0b — SUPER ADMIN PLACEHOLDER FIXES | not started |
| 3 | O1–O6 — promotional credit open decisions | not started |
| 4 | Admin email-change verification link (before franchise/finance admins exist) | not started |
| 5 | Promotional credit split + combined wallet/Razorpay + wallet_breakdown + payout leak fix | not started |
| 6 | EARN4 referrals updated for promo credit and the thumb rule | not started |
| 7 | Coupon engine C1–C3, then optional HQ pilot on the owner's go-ahead, then C4–C6 | not started |
| 8 | Re-add the classes in docs/PENDING_FRONTEND_BUILD_ITEMS.md in the next change that needs a front-end build | not started |

### 1. fee_waiver fix

See decision 1. Report every place `fee_waiver` is applied (file/line), fix, and test:
`fee_waiver` removes only the visit charge; full service price still charged; included-service
entitlements unchanged. Prod has 0 Prime subscribers, so no data fix needed; confirm by code
reading only.

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

### 8. Pending front-end classes

Re-add the classes in docs/PENDING_FRONTEND_BUILD_ITEMS.md in the next change that needs a
front-end build.

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
