# 11 — Test Plan

**Ref:** `1CF-COMPREHENSIVE-AUDIT-20260922-1110` · Consolidates the per-area "Required tests"
sections from docs 03–09. None of these were run in this pass (audit-only, no code changed).

## 1. Quantity booking (doc 03)

- Feature: quantity = 1 baseline (regression-only, must not change existing behavior)
- Feature: quantity = 2 → exactly 2 `Booking` rows, 1 `BookingBundle`, bundle total = 2× unit price
- Feature: increase quantity after adding from detail page (once stepper exists) — updates the
  existing line, does not duplicate it
- Feature: decrease to 1, decrease to 0 (0 must remove the line, not leave a ghost 0-qty line)
- Feature: duplicate/double-click submission at quantity > 1 — confirm the fanned-out children
  replay identically via the existing idempotency-key path, not duplicated
- Feature: refresh mid-checkout with quantity > 1 — per-line schedule state survives `mount()`
- Feature: payment retry after failed gateway capture on a quantity > 1 bundle — all N children stay
  in the same pending/paid state together

## 2. Cash payment and remittance (doc 04)

- Feature: cash booking completion → confirm what `payment_status` becomes once doc 04 item #1 is
  implemented (test the decided semantics, not an assumption)
- Feature: `ProviderCommissionReceivable` created correctly on cash-booking completion (regression —
  already covered by prior test suites per project history, re-run not re-write)
- Feature: remittance recording — submit, verify, reject flows once built
- Feature: partial remittance against multiple outstanding receivables
- Reconciliation: sum of `amount_owed` across all outstanding receivables for a provider matches
  admin dashboard total, before and after a remittance
- Financial: duplicate-remittance-submission prevention
- Authorization: only permitted roles can verify a remittance
- Security: a provider cannot record their own remittance as "verified"

## 3. Dispatch 30-minute policy (doc 05)

- Feature: zero eligible providers from T+0 → escalation fires at the configured mark
- Feature: one provider available who never responds → correctly times out, tries next candidate,
  eventually escalates
- Feature: admin manually assigns during the T+5–T+30 window → dispatch stops cleanly
- Concurrency: provider accepts at the exact moment admin manually assigns → exactly one wins, no
  double-assignment, clear rejection message to the loser
- Feature: auto-cancel at T+30 → booking cancelled, customer notified, live offers closed, reason +
  audit event recorded, refund triggers correctly if pre-paid
- Failure-recovery: queue/worker downtime during the window → booking recovers correctly once
  workers resume, doesn't silently vanish
- Regression: the already-fixed round-increment bug (booking #10 class of bug) stays fixed — add an
  explicit regression test for "zero candidates every round" terminating at `maxRounds()`

## 4. Admin filters and CRUD (doc 06)

- Feature: each newly-added filter tab returns the correct subset, persists across pagination
- Feature: Reviews admin screen — approve/reject/list, filter tabs
- Feature: soft-delete + restore for `Zone`/`ServiceCategory`/etc. once `SoftDeletes` is added —
  confirm a deleted-then-restored row's relationships are intact
- Authorization: every admin action re-tested against the role matrix once doc 06 §4/§5 is built
- Regression: existing delete-guard behavior (blocked when in-use) unchanged by the `SoftDeletes`
  addition

## 5. Serial numbers (doc 07)

- Visual/snapshot: serial number column renders correctly on page 1, and correctly offsets on page
  2+ (`firstItem() + $i` formula) across all ~38 newly-added tables
- Regression: existing 6 tables with the pattern are unaffected

## 6. Homepage and checkout UX (doc 02)

- Feature: location permission denial → fallback path (needs the fallback built + tested together,
  per doc 02's "unverified" flag)
- Integration: quantity tampering, price tampering, coupon abuse — re-confirm server-side rejection
  once the detail-page stepper ships (the server-side pricing itself already resists these; the new
  UI surface needs its own pass to confirm it doesn't introduce a new client-trusted field)
- Concurrency: two browser tabs completing checkout for the same cart simultaneously
- Failure-recovery: payment timeout / partial gateway failure recovery, back-button mid-checkout

## 7. Membership (doc 08)

- Feature: new membership purchase
- Feature: failed payment
- Feature: duplicate payment prevention
- Feature: active-membership booking applies the correct benefit
- Feature: expired-membership booking does not apply the benefit
- Feature: excluded service correctly denied the benefit
- Feature: used entitlement vs. remaining entitlement correctly tracked
- Feature: refund of a membership purchase
- Feature: renewal (already covered by `RenewalService`'s existing test suite per project history —
  re-run, not re-write, unless the completion-branch merge changes its behavior)
- Concurrency: two simultaneous redemption requests against the same limited entitlement don't both
  succeed (race on the "remaining balance" check)

## 8. Security (doc 09)

- Regression: suspended-user login block, re-tested after the persistent-session/all-surfaces
  hardening is merged (doc 09 §2) — confirm a user suspended mid-session is actually logged out /
  blocked on their next request, not just on next login
- Regression: provider-eligibility-at-acceptance and offer-timeout-race fixes, re-tested after merge
- Verification: production DB credential rotated; old credential confirmed non-functional
- Scoped-sweep tests (once doc 09 §6's dedicated pass is scheduled): CSRF, XSS, IDOR, tenant-scoping
  — out of this audit's depth, to be defined by that dedicated pass

## 9. Test categories cross-reference (per the brief's required breakdown)

| Category | Covered by |
|---|---|
| Unit | Pricing cascade, commission split math, receivable settlement math (§2) |
| Feature | Nearly all items above |
| Integration | §6 quantity/price tampering, §3 dispatch escalation chain |
| Browser/UI | §4/§5 admin table rendering, §2 checkout flow |
| Queue | §3 worker-downtime recovery, §5's dispatch job regression |
| Dispatch concurrency | §3's accept-vs-manual-assign race |
| Financial reconciliation | §2's receivable-sum checks |
| Authorization | §2, §4, §8 |
| Mobile responsive | Not addressed by this pass — flagged as needing its own pass across the
  admin table changes (doc 07) and the quantity stepper (doc 03), both new UI surfaces |
| Regression | Called out explicitly wherever a change touches existing, tested behavior |
| Failure recovery | §3, §6 |
