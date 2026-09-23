# 01 — Executive Summary

**Ref:** `1CF-COMPREHENSIVE-AUDIT-20260922-1110`
**Date:** 22 September 2026 · **Scope:** Customer web, Provider web, Admin panel, Services module
**Mode:** Read-only audit. No code changed, nothing deployed, no production data touched.

Full detail is in docs `02`–`14` in this directory; this is the synthesis. See `14-AGENT-STATUS.md`
for exactly what was sampled vs. exhaustively verified in each section.

## A. Current system condition

The 1CallFix booking/dispatch/payment core is **substantially more built than a surface read would
suggest**. Several capabilities the brief asked to "investigate whether they exist" turned out to
already exist correctly: server-authoritative pricing on every booking path, an atomic
parent-bundle/child-booking architecture that already matches the business's quantity-booking
target, correctly-computed cash commission splits, rate-limited auth endpoints, and generally
well-guarded destructive admin actions (blocked deletes with clear reasons, not silent data loss).

What's missing is concentrated in **three places**: (1) the layer *around* otherwise-correct
engines — cash dues have no admin visibility or remittance workflow, dispatch has no
escalation/cancellation timer, (2) UI-level gaps — the quantity stepper isn't where the business
wants it, most admin tables lack serial numbers, a Reviews moderation screen doesn't exist, and (3)
**a backlog of already-written, already-correct fixes sitting unmerged** — six security/dispatch-
integrity commits and the fuller membership system are built but not on `main`.

## B. Critical blockers

1. **A live production database password is committed in plaintext** in
   `scripts/backup-database.sh`, confirmed present in the current working tree, not just git
   history (doc 09 §1). **Requires rotation immediately, independent of any other work in this
   roadmap.**
2. **Cash-commission dues have zero admin visibility and no remittance mechanism** — the only place
   a provider's outstanding cash debt is visible to anyone is the provider's own self-service payout
   screen; there is no admin report, and settlement only happens passively when (if) a provider
   voluntarily requests a payout with a positive wallet balance (doc 04). A cash-only provider can
   accumulate unbounded, invisible debt with zero restriction on taking more cash jobs.
3. **The 5-minute admin-escalation / 30-minute auto-cancellation dispatch policy does not exist.**
   The actual behavior is ~2.5 minutes of automated retries (config-adjustable to 5, but the
   *timeout-after-window* behavior — a log line, nothing else — is what's actually missing), then a
   booking sits in `searching_provider` indefinitely with no admin notification and no scheduled
   cleanup (doc 05).
4. **Six security/dispatch-integrity fixes are written, presumably tested, and not merged to
   `main`** — suspension enforcement on every auth surface (not just password login), provider-
   eligibility enforcement at job acceptance, and an offer-timeout race fix, all sitting on a feature
   branch bundled with unrelated membership work (doc 08 §3, doc 09 §2).

## C. High-risk defects (non-blocker, but genuine)

- A completed, fully cash-paid booking's `payment_status` stays `pending` forever — no `Payment`
  record is ever created for a cash transaction (doc 04 §2.1). This silently breaks any report or
  export that filters on payment state.
- Five reference-data models (`Zone`, `ServiceCategory`, `Subcategory`, `Country`, `City`) are
  hard-delete, not soft-delete — today's admin UI guards against deleting an in-use row, but there's
  no restore path if that guard is ever bypassed by a future change (doc 06 §3).

## D. What's already working (implemented and verified this pass)

- Server-authoritative pricing cascade, used identically whether booking one service or a bundle
  (doc 03).
- The quantity-booking data model and checkout fan-out (one `BookingBundle`, N `Booking` children) —
  the architecture the business asked to have verified/built already exists (doc 03).
- Cash commission split math (platform/franchise/provider shares) — correct (doc 04).
- Rate limiting on every sensitive auth endpoint (doc 09).
- Idempotent, race-safe dispatch rounds with an already-fixed infinite-loop bug (doc 05).
- Consistently guarded destructive admin actions with clear in-use-blocking messages (doc 06).
- A real, correctly-formulated serial-number pattern — just applied to only 6 of ~45 admin tables
  (doc 07).

## E. Requiring implementation

See `12-implementation-roadmap.md` for the full phased plan. Headline items: cash-dues admin
visibility + remittance workflow (Phases 2–3), dispatch escalation/cancellation (Phase 4), quantity
stepper on the service detail page (Phase 5), admin filter/serial-number/Reviews sweep (Phase 6),
membership completion merge pending a business decision (Phase 7).

## F. Requiring runtime verification (not resolvable from source alone)

FCM/VAPID production configuration status; queue-worker/Redis health in production; whether the
already-merged homepage/search/banner work matches the (unavailable-to-this-session) UX reference
screenshots; concurrent-checkout and payment-timeout recovery behavior under real load.

## G. Traceability matrix

| Requirement (brief §) | Existing implementation | Evidence | Status | Risk | Required action |
|---|---|---|---|---|---|
| Quantity booking, one parent order (§2) | `BookingBundle` + N child `Booking` fan-out | `Checkout.php:244-268`, `CreateBookingBundleAction.php` | Implemented (backend); UI gap | Low | Phase 5 |
| Quantity stepper beside price (§2) | Cart-page only | `ServiceShow.php` has no `quantity` prop | **Missing** | Low | Phase 5 |
| Server-side pricing, no client trust (§2, §9) | `effectivePriceFor()` cascade | `CreateBookingAction.php`, doc 03 §1.3 | Implemented and verified | — | — |
| Cash commission calculation (§3) | 3-tier rate resolver + split | `CommissionService::applyForBooking()` | Implemented and verified | — | — |
| Cash dues admin visibility (§3) | None | Grep sweep, doc 04 §2.1 | **Missing** | High (financial) | Phase 2 |
| Direct remittance workflow (§3) | None | doc 04 §2.5 | **Missing** | High (financial) | Phase 3, blocked on decision |
| Cash payment record / receipt (§3) | None — no `Payment` row, `payment_status` stuck `pending` | doc 04 §2.1 | **Unsafe / financially incomplete** | High | Phase 1, blocked on decision |
| 5-minute dispatch escalation (§4) | Not implemented (only a log warning) | `ServiceMatchingJob.php:136-139` | **Missing** | High (operational) | Phase 4 |
| 30-minute auto-cancellation (§4) | Not implemented — no scheduled sweep exists | `routes/console.php` (full read) | **Missing** | High (operational) | Phase 4 |
| Manual admin reassignment (§4) | Exists | `AdminReassignBookingAction`, `Bookings/Show.php` | Implemented and verified | — | — |
| Admin filters / "All" tabs (§5) | Present on 23/45 sections | doc 06 §1 | Partially implemented | Low-medium | Phase 6 |
| Reviews moderation screen (§5, §6) | Does not exist | Exhaustive `find` sweep, doc 06 §2 | **Missing** | Medium | Phase 6 |
| Financial-record hard-delete protection (§6) | Respected — no financial model is deletable from admin | doc 06 §3 | Implemented and verified | — | — |
| Reference-data soft-delete (§6) | Missing on 5 models, but guarded | doc 06 §3, doc 10 §2 | Partially implemented | Low-medium | Phase 6 |
| Serial numbers (§7) | 6/45 tables | doc 07 | Partially implemented | Low | Phase 6 |
| Homepage location/search/banners (§8) | Built in prior phases | Project history, not fully re-verified this pass | Implemented, unverified against screenshots | Low | — |
| Inline quantity on catalog/detail (§8, §9) | Missing (same as row 2) | — | **Missing** | Low | Phase 5 |
| Duplicate-checkout protection (§9) | Idempotency-key + fingerprint | `CreateBookingBundleAction::replay()` | Implemented and verified | — | — |
| Membership base engine (§10) | Live on `main` | doc 08 §2 | Implemented and verified | — | — |
| Membership completion (waiver, category targeting) (§10) | Built, **not merged** | doc 08 §1/§3 | Missing on `main` | Medium (financial via waiver) | Phase 7, blocked on decision |
| Suspension enforcement — password login (§11) | Live | `Login.php:71` | Implemented and verified | — | — |
| Suspension enforcement — all auth surfaces (§11) | Built, **not merged** | doc 09 §2 | Missing on `main` | High (security) | Phase 0b |
| Rate limiting (§11) | Live | `routes/api.php` | Implemented and verified | — | — |
| Production credential hygiene (§11) | **Failed** | `scripts/backup-database.sh:16` | **Critical** | Critical | Phase 0a, immediate |
| CSRF/XSS/IDOR/tenant-scoping systematic sweep (§11) | Not audited at required depth this pass | doc 09 §6 | Unverified | Unknown | Phase 8 |

## H. Immediate recommendation

Start Phase 0 (credential rotation + splitting/merging the six stranded security fixes) today,
independent of any prioritization discussion — everything else in the roadmap can proceed in
whatever order the business prefers.
