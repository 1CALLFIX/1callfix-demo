# 10 — Database and Domain Impact Summary

**Ref:** `1CF-COMPREHENSIVE-AUDIT-20260922-1110` · Consolidates the per-area "Required changes"
tables from docs 02–09 into one schema/domain-impact view. No migrations were written or run in
this pass.

## 1. New tables

| Table | Purpose | Source doc | Risk |
|---|---|---|---|
| `provider_remittances` (name indicative) | Auditable record of a provider's direct remittance to the platform: method, reference, proof, submitted/verified by, status, and the receivable(s) it settles | doc 04 §4.2 | Medium — additive only, no existing table touched |

## 2. New columns on existing tables

| Table | Column(s) | Purpose | Source doc | Risk |
|---|---|---|---|---|
| `bookings` | `dispatch_deadline_at` (or derive from `created_at` + `Setting`) | Drives the 30-minute auto-cancel sweep | doc 05 §6.3 | Low |
| `zones`, `service_categories`, `subcategories`, `countries`, `cities` | `deleted_at` (`SoftDeletes`) | Currently hard-delete models referenced by historical bookings/services; making them soft-deletable removes the "no restore path" risk even though current guards already block in-use deletes | doc 06 §5.3 | Low-medium — every query against these models needs confirmation the default global scope behaves as expected |

## 3. Columns/tables already added but not yet merged to `main`

These exist on `feature/membership-prime-silver` (unmerged — see doc 08) and should be evaluated
as a unit once the provider-payout-on-waived-jobs decision (doc 13 §5) is made, not re-designed:

- `services.visiting_charge`
- Membership fields on `subscriptions` and `bookings`
- `plan_entitlement_targets` table (new model: `PlanEntitlementTarget`)
- `config/membership.php`

## 4. Domain-service impact

| Service/Action | Change required | Source doc |
|---|---|---|
| `ServiceCartService` / `ServiceShow` Livewire | Add a quantity property + increment/decrement methods reusing the existing cart-line add/update path | doc 03 |
| `PayoutService` | No change to the settlement math itself; a new `RecordProviderRemittanceAction` sits alongside it, doesn't replace `settleCashCommissionReceivables()` | doc 04 |
| `DispatchService` / `ProviderAvailabilityService` | Only if doc 13 §1's debt-threshold decision requires it — a new eligibility check | doc 04 |
| `ServiceMatchingJob` | New escalation state transition + `AdminOpsAlertService` call on round exhaustion | doc 05 |
| New `dispatch:expire-stale` console command | Auto-cancel at deadline, reusing `AdminCancelBookingAction`/`CancellationService` — no new cancellation logic | doc 05 |
| Admin Reviews screen (new) | New Livewire component + minimal service layer around the existing `Review` model/`ReviewService` | doc 06 |
| `MembershipBenefitService`, `MembershipPresenter`, `PlanEntitlementTarget` | Already written on the unmerged branch; review + merge, not rebuild | doc 08 |

## 5. Explicitly NOT recommended

- **No changes to `Booking`, `BookingBundle`, `CreateBookingAction`, or `CreateBookingBundleAction`
  for the quantity requirement** — the fan-out architecture already satisfies it (doc 03 §1.4 item
  4). Building a `quantity` column directly on `Booking` would be a regression, not an improvement —
  it would break the "each unit is independently dispatchable" property that's already correct.
- **No rewrite of the commission-split math** for cash payments (doc 04 §2.3) — it's correct today;
  only the settlement/visibility/remittance layer around it needs work.
- **No change to the core dispatch round mechanism** (`ServiceMatchingJob`'s per-round logic, its
  row-locking, its already-fixed round-increment bug) — only an escalation/cancellation layer needs
  to be added on top.

## 6. Financial-impact flags (per the brief's rule to isolate high-stakes phases)

Every item touching `ProviderCommissionReceivable`, `Payment`, `Commission`, `Payout`, or wallet
debit/credit paths (doc 04's roadmap items, doc 09 §2's cash-debt-restriction possibility) should be
its own controlled implementation phase with its own reconciliation test pass — never bundled with
UI-only changes like the quantity stepper or serial numbers. This is reflected in doc 12's phase
ordering.
