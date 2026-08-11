# PHASE B0.3 — Dispatch Polymorphism Foundation

**Status:** Implemented locally, to be verified via a test-and-rollback cycle, **not committed, not deployed**.

**Builds on:** Phase A (frozen, `4f92fdf`). Phase B0.1 + B0.2 (committed locally at `d1b2e91`, not yet pushed to production).

> **This document exists to state one thing unambiguously: B0.3 only prepares the schema and service surface for a future polymorphic dispatch actor. It does NOT activate polymorphic dispatch behavior. The existing Provider-only dispatch flow (`ServiceMatchingJob`, `DispatchService::findCandidates()`, `AcceptBookingAction`) is completely unchanged and continues to use `dispatch_attempts.provider_id` exactly as before. Nothing in this codebase reads or writes the new columns yet.**

---

## What B0.3 is

Two small, additive, low-risk changes, both explicitly scoped in advance and approved before implementation:

1. **One migration**: adds nullable `notifiable_type` / `notifiable_id` columns to `dispatch_attempts`, alongside the existing `provider_id` — not replacing it, not backfilling it, not touching its meaning.
2. **One visibility change**: 4 of `DispatchService`'s private helper methods (`eligibleQuery`, `hasSkill`, `withDistance`, `rankAndLimit`) become `protected`. Zero behavior change — a future, separately-approved worker-aware dispatcher could reuse these without duplicating them, but nothing does yet.

## A correction found during pre-implementation inspection

The approved scope named "the five specified DispatchService methods." On inspection, `DispatchService` has only **4** actually-`private` methods matching the description; the fifth (`haversineKm`) is **already `public`**. Since `public` is already at least as accessible as `protected`, no change was made to it — narrowing it to `protected` would reduce its accessibility for no benefit and wasn't requested. This is a factual correction, not a scope expansion: 4 methods changed, not 5, and nothing else about `DispatchService` was touched.

## What B0.3 explicitly does NOT do

- Does not implement polymorphic dispatch behavior.
- Does not change `ServiceMatchingJob`, `DispatchService::findCandidates()`/`nearbyForService()`, or `AcceptBookingAction` to use the new columns.
- Does not build `ParcelMatchingJob` or any Parcel code.
- Does not resolve or silently decide any B0.1/B0.2 open business decision.
- Does not touch Phase A.

## Database change

```
dispatch_attempts
  + notifiable_type   VARCHAR, nullable
  + notifiable_id     UNSIGNED BIGINT, nullable
  + index (notifiable_type, notifiable_id)   -- explicitly named dispatch_attempts_notifiable_idx
```
`provider_id` (the real, active column every existing dispatch read/write uses) is untouched.

## Why this shape

Matches this session's established additive-polymorphic-column pattern (`subscriptions.subscribable_type/id`, `notification_logs.notifiable_type/id`) rather than a parallel table — chosen because `dispatch_attempts` is an audit/offer log, not a domain "order," so one shared log for whichever actor type is eventually offered a job is the more natural fit than duplicating the table per actor type. This mirrors the recommendation from the original Phase B0 architecture report; it is not a re-litigation of that choice, just the first concrete step toward it.

## Verification (to be run before any commit)

Same discipline as B0.1/B0.2: verify production baseline first, migrate up (all pending B0.1+B0.2+B0.3 migrations together, since production doesn't have B0.1/B0.2 applied yet either), full regression — real dispatch flow through `ServiceMatchingJob`/`AcceptBookingAction`/`CompleteBookingAction` unchanged, B0.1/B0.2 worker-delegation flow still functions with the new columns present (and confirmed untouched/null), Phase A smoke test — then migrate everything back down, restore all server files, confirm production returns to exactly `4f92fdf`/migration `039000`. No deployment.

## Open decisions (unchanged — none resolved by this phase)

All open items from B0.1 and B0.2 remain open, unaddressed by this schema-only step. Additionally still open: whether `dispatch_attempts` actually becomes the shared polymorphic log for a future worker-aware dispatcher, or whether a parallel table is used instead when that dispatcher is actually built — B0.3 only makes the polymorphic option *available*, it does not commit to using it.
