# PHASE B0.1 — Universal Worker Foundation

**Status:** Implemented locally, verified against the real production database via a test-and-rollback cycle, **not committed, not deployed**. Awaiting explicit instruction to commit/push/migrate for real.

**Built on:** Phase A (frozen, commit `4f92fdf`) — untouched throughout.

---

## What was implemented

A vertical-agnostic **Worker/Field-Agent identity layer**, structurally a sibling of `Provider` (Partner), not a subtype of it and not merged into it — per the approved Phase B0 architecture report.

- `field_workers` — the universal field-execution profile, 1:1 with `users`.
- `field_worker_capabilities` — one-to-many; a worker can hold several capability rows (e.g. `service_technician` + `handyman`) rather than being restricted to a single type column.
- `partner_workers` — the Partner↔Worker relationship as a link table, not a column on either side. A worker can have zero, one, or (schema-supported) several active links.
- `field_worker_documents` — a dedicated KYC/document table mirroring `provider_documents`' exact shape.
- `App\Support\WorkerTypes` — a registry class mirroring `App\Support\Modules`'s exact style: 7 approved capability slugs (`service_technician`, `handyman`, `helper`, `parcel_rider`, `taxi_driver`, `food_delivery_rider`, `grocery_delivery_rider`), string-keyed, not a DB enum.
- `User::fieldWorkerProfile()` — additive relation, alongside the existing `providerProfile()`, not replacing it.
- `Provider::partnerWorkerLinks()` / `Provider::workers()` — additive relations exposing which FieldWorkers a Provider (Partner) is linked to.
- 6 new RBAC permissions: `worker.jobs.view`, `worker.jobs.accept`, `worker.availability.manage`, `worker.documents.manage`, `partner.workers.manage`, `partner.workers.assign` — seeded via the same additive migration pattern as every prior permission round this project has used, granted to Super Admin only for now.

## What was deliberately NOT implemented

Per the explicit B0.1 scope boundary — all of the following remain untouched:

- Parcel orders, Parcel pricing, `ParcelMatchingJob`, Parcel API
- Taxi orders, Taxi dispatch
- Service delegated execution (`bookings.assigned_worker_id` does not exist yet)
- The actual Partner-assigns-Worker workflow (permissions are seeded, but nothing enforces or exercises them against a real assignment yet)
- Polymorphic `dispatch_attempts`
- Any change to `ServiceMatchingJob`, `DispatchService`, or `CommissionService`
- Worker commission/settlement/payroll/scheduling
- Any Flutter application (Rider, Partner, or Customer)
- The dormant `Provider.provider_type=company` / `parent_provider_id` / `technicians()` scaffolding — left exactly as found, not touched, not repurposed

## Schema

| Table | Key columns |
|---|---|
| `field_workers` | `user_id` (unique FK), `franchise_id`, `zone_id`, `kyc_status`, `is_online`, `current_lat/lng`, `location_updated_at`, `rating_avg`, `jobs_completed`, `is_active`, soft-deletes |
| `field_worker_capabilities` | `field_worker_id`, `capability_type` (string), `service_category_id` (nullable) |
| `partner_workers` | `provider_id`, `field_worker_id`, `status` (`pending`\|`active`\|`suspended`, mirrors `business_accounts.status`), `is_primary`, unique on `(provider_id, field_worker_id)` |
| `field_worker_documents` | `field_worker_id`, `type`, `file_url`, `status` (`pending`\|`approved`\|`rejected`), `rejection_reason` |

All 5 migrations (4 tables + 1 permission seed) are purely additive — no existing table's meaning changed, no destructive operation anywhere.

## Relationships

```
User
 ├── providerProfile()      (existing, unchanged)
 └── fieldWorkerProfile()   (new)

FieldWorker
 ├── user()
 ├── franchise() / zone()
 ├── capabilities()  → FieldWorkerCapability
 ├── documents()     → FieldWorkerDocument
 ├── partnerLinks()  → PartnerWorker
 └── partners()      → Provider (belongsToMany, through partner_workers)

Provider (unchanged relations) + new:
 ├── partnerWorkerLinks() → PartnerWorker
 └── workers()            → FieldWorker (belongsToMany, through partner_workers)
```

## Capability model

One `FieldWorker` row, N `FieldWorkerCapability` rows. `capability_type` values come from `App\Support\WorkerTypes::ALL`. `service_category_id` applies only to Service-vertical capabilities (`service_technician`, `handyman`); it's null for `parcel_rider`/`taxi_driver`/etc. Whether a worker is *operationally* allowed to hold multiple capabilities at once remains an **open business decision** — the schema does not foreclose it either way.

## RBAC

6 new permissions, 2 groups (`Worker`, `Partner Workforce`), granted to Super Admin only — same pattern as every prior permission round. No existing permission, role, or role_assignment was touched. Total permission count after this phase: 36 (30 existing + 6 new).

## Test results

Verified against the real production database via a **test-and-rollback cycle** (migrated up, ran a realistic disposable scenario, verified cleanup, then rolled the schema back down and restored all server files to exactly commit `4f92fdf` — nothing was left deployed).

**Scenario:** User A (AC shop owner) + a second Partner User D → both linked to Worker B (a handyman with `service_technician` + `handyman` capabilities), proving multi-partner schema support; Worker C (an independent parcel rider with `parcel_rider` only) deliberately left with **no** partner link, proving platform-direct dispatch is valid.

**48/48 checks passed, zero bugs found**, covering: Worker creation, DB-enforced 1:1 User↔FieldWorker protection, multi-capability without row duplication, Partner↔Worker linking, multi-partner linking, platform-direct workers, documents, RBAC recognition (both grant and correct denial), **regression checks confirming existing Provider/User/RBAC behavior is unchanged**, and a **live Phase A smoke test** (a real zero-price Plan subscription + a real booking through `CreateBookingAction`, confirming the Plan Engine's entitlement discount still applies correctly after the B0.1 migrations ran).

## Cleanup results

All test fixtures (6 users, 2 providers, 2 field_workers, 1 plan, 1 role, 1 booking, 1 subscription, and their dependents) deleted and verified via 16 direct-DB integrity queries — zero orphans, zero leftover rows matching the test marker, permission/role counts back to exact baseline+new-seed totals, `jobs=0`, `failed_jobs` unchanged at its historical baseline of 1. One transient `jobs=1` reading during verification was traced to the smoke-test booking's own (unavoidable) `ServiceMatchingJob` dispatch, confirmed to have drained via the existing cron worker as a harmless no-op within the minute — not a bug.

## Open decisions remaining (unchanged from the B0 architecture report — none resolved by this phase, none silently decided)

1. Can a Worker hold an active relationship with more than one Partner *in practice* (schema supports it, confirmed by this phase's own test — the business rule is still open)?
2. Can a Worker hold multiple vertical capabilities simultaneously in real operation (schema supports it — operational policy is still open)?
3. Worker financial model — platform commission split vs. Partner-paid wage, vs. hybrid.
4. Does an independent/solo Provider get a paired `field_workers` row automatically, or is that an explicit opt-in?
5. Exact KYC requirements per capability type.
6. `dispatch_attempts` polymorphic widening vs. a parallel table (recommended: polymorphic, not yet built).
7. Worker documents: this phase built a dedicated table, per explicit instruction — the polymorphic-widening alternative from the B0 report is no longer under consideration for this table.

## Next slice (not started)

Per the recommended implementation order: resolve the open decisions above, then (separately, on explicit instruction) wire `bookings.assigned_worker_id` and the actual Partner-assigns-Worker workflow into the existing Service booking lifecycle — the one place this foundation will eventually touch already-live code, kept deliberately minimal when it happens.
