# 08 — Membership Functionality Audit

**Ref:** `1CF-COMPREHENSIVE-AUDIT-20260922-1110` · Read-only, no code changed.

## 1. Two membership branches exist — verified which one is actually live

This pass found and resolved a real discrepancy between prior session notes and current repo
state via direct `git log`/`git merge-base` checks (not assumed from memory):

| Branch | Tip commit | Merged to `main`? | Contents |
|---|---|---|---|
| `feature/prime-silver-membership` | `fa2b34a` | **Yes** (merge `d5915a1`, on `main`) | Base Plan Engine entitlement-redemption capability (`RedeemEntitlementAction`, additive columns on `plans`/`plan_entitlements`/`usage_ledger`, `PrimeSilverPlanSeeder`, admin UI) |
| `feature/membership-prime-silver` | `d65ac3a` | **No** — 8 commits ahead of `main`, not merged | The fuller membership completion: `MembershipBenefitService` (342 new lines), `MembershipPresenter` (152 new lines), `PlanEntitlementTarget` model, `visiting_charge` column on `services`, membership fields on `subscriptions`/`bookings`, `config/membership.php`, customer-facing pages |

**Confirmed via `ls app/Services/Plans/` against the current `main` checkout:** `MembershipBenefitService.php`
and `MembershipPresenter.php` do not exist on `main` today. A `grep` for `visiting_charge` against
the current `main` migrations directory returns nothing — the column does not exist in the
database `main` would migrate. **What is actually live today is only the base entitlement-redemption
engine — the generic benefit→catalog-target system, visiting-charge waiver, and customer-facing
membership pages described in prior session work are built but sitting unmerged.**

This is an important correction to carry into the roadmap: any claim that "membership is done" is
only true for the base engine. The completion work needs its own merge/review/deploy decision
before this audit's roadmap can assume it exists in production.

## 2. What's confirmed live on `main` (verified this pass)

- `app/Services/Plans/{EligibilityService,EntitlementService,OverageService,PlanService,PlanStackingResolver,RenewalService,SubscriptionService,UsageService}.php` — the full base Plan Engine.
- `RedeemEntitlementAction` — lets a named quantity entitlement (e.g. "2 free AC services this month")
  be redeemed against a booking, server-side.
- `plans:renew-due` scheduled hourly (`routes/console.php:34`) — active/past_due/grace_period/expired
  lifecycle progression confirmed scheduled (not just coded).
- Admin UI for plan management (`Plans/Manage.php`), subscriptions list (`Subscriptions/Index.php`,
  confirmed to have a filter property per doc 06).

## 3. What's built but not merged (`feature/membership-prime-silver`, 8 commits)

Per the diff-stat gathered this pass (`git diff --stat main feature/membership-prime-silver`):
- `MembershipBenefitService.php` (new, 342 lines) — a generic benefit→catalog-target resolver,
  i.e. the mechanism to let a membership say "waive the visiting charge" or "20% off Category X"
  without hardcoding it to the entitlement-redemption model.
- `MembershipPresenter.php` (new, 152 lines) — customer-facing membership display logic.
- `PlanEntitlementTarget` model (new) — lets an entitlement target a *category* rather than only a
  named quantity, closing a real limitation of the base engine (a pricing resolver "can't target a
  named quantity entitlement," per the branch's own commit history).
- `visiting_charge` column added to `services` — the DB support needed for a visiting-fee waiver
  benefit to exist at all.
- Membership fields added to `subscriptions` and `bookings`.
- Changes to `Provider/Jobs/Index.php`, `Provider/OfferWatcher.php`, `Provider/OnlineToggle.php`,
  and `ProviderJobOfferNotification.php` — **these are dispatch/notification changes bundled into
  the same branch**, not membership-specific. Also present in this branch and **not** in `main`:
  provider-eligibility enforcement at booking acceptance, suspension checks on persistent Livewire
  requests, and a location-freshness gate on Services dispatch (commit messages: "Enforce provider
  eligibility at booking acceptance," "Enforce suspension checks on persistent Livewire requests,"
  "Add server-side location freshness gate to Services dispatch," "Enforce account suspension across
  every authentication surface," "Close provider offer timeout race at acceptance"). **These read as
  security-relevant fixes bundled onto a feature branch, not membership work** — flagged as a
  priority item for the roadmap independent of the membership decision, since a security fix sitting
  unmerged for this long is itself a finding (see doc 09).

## 4. Membership lifecycle — traced against the brief's requirements

| Requirement | Base engine (on `main`) | Completion work (unmerged) |
|---|---|---|
| Plan creation/pricing/duration | Implemented | — |
| Start/expiry date, active/inactive status | Implemented (`RenewalService`, scheduled hourly) | — |
| Customer purchase | Implemented | — |
| Payment success/failure handling | Not independently re-verified this pass | — |
| Renewal | Implemented (`RenewalService`) | — |
| Service entitlements (named quantity) | Implemented (`RedeemEntitlementAction`) | — |
| Category-level benefit targeting | **Not present** | Implemented (`PlanEntitlementTarget`) |
| Visiting-charge waiver | **Not present** (no `visiting_charge` column on `main`) | Implemented |
| Server-authoritative benefit calculation | Implemented for redemption (`RedeemEntitlementAction`); **the general benefit resolver (`MembershipBenefitService`) that would authoritatively apply category-level/waiver benefits does not exist on `main`** | Implemented |
| Excluded services / applicable categories | Depends on `PlanEntitlementTarget`, which is unmerged | Implemented |
| Duplicate purchase prevention | Not independently re-verified this pass | — |
| Expiry notifications | `SubscriptionStatusNotification` exists per the unmerged branch's diff-stat (14 lines changed) — implies it already existed on `main` in some form; not independently confirmed this pass | — |

## 5. Open business decision carried over from prior session work

**Provider payout on waived jobs** — when a membership benefit waives the visiting charge (or
another charge) for the customer, does the provider still get paid their normal share for that
component, or is it absorbed? This is recorded in prior project memory as an explicitly open
question on the unmerged branch and was not resolved by this pass — it blocks the merge decision
for `feature/membership-prime-silver`, since `MembershipBenefitService`'s payout-adjacent behavior
depends on the answer.

## 6. Required changes (roadmap input — not implemented in this pass)

| # | Change | Risk |
|---|---|---|
| 1 | Decide the provider-payout-on-waived-jobs question (§5) — blocks everything else in this section | Business decision, not code |
| 2 | Split the unmerged branch: the security-relevant dispatch/suspension fixes (§3) should be reviewed and merged **independently and sooner** than the membership completion work — they are unrelated concerns bundled together and the security fixes look overdue | Merge/review process, not new code |
| 3 | Once #1 is resolved, review + merge the membership completion work (`MembershipBenefitService`, `MembershipPresenter`, `PlanEntitlementTarget`, `visiting_charge`) through the same controlled process every other phase in this project has used | Medium — new tables/columns, touches pricing-adjacent code |
| 4 | Re-verify duplicate-purchase prevention and payment-failure handling on the base engine (not confirmed this pass either way) | Low — verification only |

## 7. Required tests (per the brief's list, none run this pass)

New membership purchase; failed payment; duplicate payment; active-membership booking; expired-
membership booking; excluded service; used entitlement; remaining entitlement; refund; renewal;
concurrent requests. All scheduled into doc 11, none executed in this audit pass.
