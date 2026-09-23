# 12 — Implementation Roadmap

**Ref:** `1CF-COMPREHENSIVE-AUDIT-20260922-1110` · No implementation has occurred. This orders the
findings from docs 02–09 into small, controlled phases, per the brief's explicit rule against
combining unrelated high-risk changes into one uncontrolled phase. Each phase lists objective,
affected files/modules, DB/security/financial impact, tests required, rollback approach,
verification criteria, dependencies, and risk level.

---

## Phase 0 — Immediate security remediation (no business decision needed)

**Objective:** close the live credential exposure and land the already-written security fixes that
are sitting unmerged.

- **0a. Rotate the production DB password**, parameterize `scripts/backup-database.sh` off `.env`.
  Files: `scripts/backup-database.sh`. DB impact: none (credential rotation is an ops action, not a
  migration). Security impact: closes doc 09 §1. Financial impact: none. Tests: confirm the backup
  script still runs against the new credential. Rollback: trivial (script edit only; credential
  rotation itself has no rollback — proceed regardless). Verification: old credential rejected by
  the DB server. Dependencies: none. **Risk: Low. Urgency: immediate, independent of everything
  else in this roadmap.**
- **0b. Review and merge the six stranded fixes on `feature/membership-prime-silver`**
  (`f626cf5, 657a8a6, 654889b, 5e45ee7, 394e926, 31f9265` — suspension-on-every-surface,
  location-freshness gate, provider-eligibility-at-acceptance, offer-timeout race, provider offer
  listing) **independently of the membership work on the same branch.** Files: as listed in doc 08
  §3. DB impact: none beyond what those commits already carry (confirm no membership-table
  dependency creeps in during the split). Security impact: closes doc 09 §2's gap. Tests: doc 11 §8.
  Rollback: standard git revert, these are self-contained fixes per their commit messages.
  Verification: doc 11 §8's regression tests pass. Dependencies: requires actually separating these
  commits from the membership-specific commits on the same branch (a cherry-pick or rebase
  exercise) — **do this before Phase 4**, don't wait on the Phase 4 business decision.
  **Risk: Low-medium. Urgency: high.**

---

## Phase 1 — Cash payment record integrity

**Objective:** make a completed cash booking's payment state truthful (doc 04 §2.1, item #1).

Files: `CompleteBookingAction`. DB impact: none if the fix is "assign `payment_status`," a new
`Payment` row if the fix is "create a captured cash payment record" (pending doc 13 §1's decision on
what "paid" means for cash). Security impact: none. Financial impact: **high** — this changes what
every downstream report/export reads for cash bookings; must not retroactively rewrite historical
rows without a separate, explicit backfill decision. Tests: doc 11 §2 first two items. Rollback:
revert the action change; no data migration to unwind if scoped to new bookings only. Verification:
a cash booking's `payment_status` reflects the agreed semantics after completion. Dependencies:
**doc 13 §1's first sub-decision** (what "paid" means). **Risk: Medium. Depends on a business
decision — do not implement ahead of that answer.**

---

## Phase 2 — Cash dues visibility (no remittance workflow yet)

**Objective:** give admin a read-only view of outstanding cash commission per provider — the
single highest-value, lowest-risk fix from doc 04, since it requires no new financial-flow decision.

Files: new admin Livewire component (read-only, lists `ProviderCommissionReceivable`, reuses
existing `[All][Outstanding][Settled]` filter pattern from doc 06). DB impact: none — reads the
existing table. Security impact: standard admin-permission gate, same pattern as every other admin
list (doc 06 §3/§4). Financial impact: none (read-only). Tests: a rendering/authorization test, no
financial-mutation tests needed. Rollback: trivial (new screen only). Verification: outstanding
totals match `PayoutService::outstandingCashCommission()`'s existing math exactly. Dependencies:
none — can ship before any remittance-workflow decision is made. **Risk: Low. Can start
immediately, in parallel with Phase 0.**

---

## Phase 3 — Direct remittance workflow

**Objective:** the actual `provider_remittances` table, submit/verify UI, and reconciliation.

Files: new migration + model, `RecordProviderRemittanceAction`, admin UI extending Phase 2's screen,
provider-facing submission UI. DB impact: one new table (doc 10 §1). Security impact:
submit-vs-verify must be different roles (doc 11 §2's explicit test). Financial impact: **high** —
this is the core of the business's cash-remittance requirement; needs the fullest reconciliation
test pass in doc 11 §2. Tests: doc 11 §2 in full. Rollback: new table, additive — can be disabled by
hiding the UI without a data migration. Verification: a full remit → verify → receivable-settled
cycle reconciles to zero outstanding. Dependencies: **doc 13 §1's remaining sub-decisions**
(methods, cadence), Phase 2 shipped first (reuses its screen). **Risk: Medium-high (financial-core
change). Do not bundle with any other phase.**

(Debt-threshold dispatch restriction, doc 04 item #6 / doc 13 §1's last bullet, is deliberately
**not** in this roadmap as a committed phase — it needs its own decision and, if approved, its own
isolated phase given its direct effect on provider earnings/dispatch fairness.)

---

## Phase 4 — Dispatch escalation and 30-minute cancellation

**Objective:** the missing T+5 escalation and T+30 auto-cancel from doc 05.

Files: `Booking` status enum, `ServiceMatchingJob`, new `dispatch:expire-stale` console command +
`routes/console.php` entry, `Bookings/Index.php` + `Show.php` (urgency/elapsed columns, dispatch-
attempts timeline). DB impact: possibly one new column (`dispatch_deadline_at`) or derive from
`Setting` — doc 10 §2. Security impact: none directly; the new scheduled command must reuse the
existing row-lock pattern to stay race-safe (doc 05 §6.3). Financial impact: cancellation triggers
the existing `CancellationService`/refund path — **must be tested against a pre-paid booking**
specifically (doc 11 §3). Tests: doc 11 §3 in full, especially the accept-vs-auto-assign race and
worker-downtime recovery. Rollback: the new scheduled command can be disabled independently of the
escalation-state change if either needs to be pulled back separately. Verification: a booking with
zero eligible providers escalates at the configured mark and cancels at T+30 in a staging run, not
just in a unit test. Dependencies: doc 13 §2 (are the 5/30 numbers global or configurable — decide
before hardcoding either way). **Risk: Medium — touches the booking FSM and a scheduled
auto-cancellation, both inherently higher-stakes; isolate from Phases 1–3.**

---

## Phase 5 — Quantity stepper on the service detail page

**Objective:** close doc 03's UI gap without touching the (already-correct) fan-out architecture.

Files: `ServiceShow` Livewire component, its Blade view, reusing `ServiceCartService`. DB impact:
none. Security impact: none beyond re-confirming the existing server-side quantity/price
recomputation still holds with the new entry point (doc 11 §6). Financial impact: none (pricing
logic untouched). Tests: doc 11 §1. Rollback: trivial UI-only revert. Verification: quantity set
from the detail page produces an identical `BookingBundle`/`Booking` outcome to the same quantity
set from the cart page today. Dependencies: doc 13 §3 (post-increment navigation behavior).
**Risk: Low.**

---

## Phase 6 — Admin filters, Reviews screen, serial numbers

**Objective:** the mechanical, low-risk, high-volume admin polish from docs 06 and 07.

Files: ~21 admin components needing a filter-vs-no-filter decision (doc 06 §1), a new Reviews admin
screen, ~38 Blade table views needing the `firstItem() + $i` serial-number column, `SoftDeletes` on
5 reference-data models. DB impact: 5 new `deleted_at` columns (doc 10 §2) — additive, low risk.
Security impact: Reviews screen needs the same permission-gate pattern as every other admin action
(doc 06 §4). Financial impact: none. Tests: doc 11 §4/§5. Rollback: each sub-item is independently
revertible; this phase can ship incrementally rather than as one atomic release. Verification: doc
07's formula check re-confirmed on every newly-added table. Dependencies: none — fully independent
of every other phase, good candidate to run in parallel with Phases 1–5. **Risk: Low, but large in
surface area — sequence as several small PRs, not one.**

---

## Phase 7 — Membership completion merge

**Objective:** land `feature/membership-prime-silver`'s membership-specific commits (with the
security fixes already split out in Phase 0b).

Files: `MembershipBenefitService`, `MembershipPresenter`, `PlanEntitlementTarget`, `visiting_charge`
column, membership fields on `subscriptions`/`bookings`, `config/membership.php`. DB impact: as
listed in doc 10 §3. Security impact: none beyond what Phase 0b already extracted. Financial impact:
**high** — a visiting-charge waiver directly affects provider earnings; this is exactly why doc 13
§5's decision gates this phase. Tests: doc 11 §7 in full. Rollback: the branch is already built and
presumably tested in its own prior session — rollback is a standard revert of the merge commit.
Verification: doc 11 §7's full membership lifecycle test matrix. Dependencies: **doc 13 §5 (provider
payout on waived jobs) — hard blocker**, Phase 0b done first (branch split). **Risk: Medium-high
(financial-core change via the waiver mechanism). Do not start until the decision lands.**

---

## Phase 8 — Dedicated security sweep

**Objective:** the CSRF/XSS/IDOR/tenant-scoping systematic pass flagged as out-of-depth in doc 09 §6.

This is intentionally scoped as its own phase using the project's existing `security-review`/
`code-review` tooling rather than folded into this audit, per the brief's instruction not to claim
runtime verification where only source inspection occurred. **Risk: to be determined by that pass's
own findings.**

---

## Suggested sequencing

```
Immediate, parallel:      Phase 0a (credential rotation) ── independent
                           Phase 0b (split + merge stranded security fixes)
                           Phase 2 (cash dues visibility, read-only)
                           Phase 6 (admin polish, several small PRs)

After Phase 0b:            Phase 4 (dispatch escalation/cancellation)
                           Phase 5 (quantity stepper)

Pending business decisions: Phase 1 (cash payment-status semantics)  ← doc 13 §1
                           Phase 3 (remittance workflow)             ← doc 13 §1
                           Phase 7 (membership completion)           ← doc 13 §5

Anytime, independent:      Phase 8 (dedicated security sweep)
```

No phase above depends on another phase's code except where explicitly stated (Phase 3 on Phase 2's
screen, Phase 7 on Phase 0b's split). This lets Phase 0/2/6 start immediately without waiting on any
of the four open business decisions in doc 13.
