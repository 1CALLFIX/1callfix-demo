# Phase 22.1 — Module Activation Foundation

**Status: COMPLETE.** Closes the specific gap `PHASE_22_PLATFORM_CAPABILITY_RECOVERY_AUDIT.md §3/§6/§11/§16` documented: no `Country → City → Zone → Franchise` module-activation cascade existed anywhere in the codebase, and the one existing per-franchise toggle (`franchise_modules`) had zero code reading it. This phase builds the real mechanism, wires it into the one real enforcement point every booking passes through, and proves both with tests — not just the resolver's logic in isolation, but that it actually blocks/allows real booking creation.

No Branch level was introduced (mission brief principle #7 — current geography stops at Franchise). No business decision was required to build this; every future module still defaults to fully disabled, per principle "a module registry may contain future modules, but an unimplemented module must never appear as usable merely because its key exists."

---

## What was built

### Schema (all additive — no existing table modified beyond one new nullable-with-default column)

| Migration | What |
|---|---|
| `2026_08_16_900000_add_is_implemented_to_modules_table` | Adds `modules.is_implemented` (boolean, default false). Backfills `true` for `service` only — the one real operational module today. This is the hard gate: `ModuleActivationService::isActive()` refuses to report ANY module active, at any scope, unless this is true, regardless of what any activation row says. |
| `2026_08_16_901000_create_module_activations_table` | New table: `module_id`, `scope_type` (zone/franchise/city/country), `scope_id`, `is_active`, `created_by_user_id`, unique on `(module_id, scope_type, scope_id)`. This is the cascade the audit found missing. |
| `2026_08_16_902000_backfill_service_module_activations` | Backfills an explicit `service=true` franchise-scope row for every pre-existing franchise, so no existing franchise silently relies on the legacy-default fallback forever — it gets a real, visible, admin-editable row instead. |
| `2026_08_16_903000_seed_modules_manage_permission` | New `modules.manage` permission, super_admin-only by default, same pattern as every other genuinely-new capability this codebase has added (`franchise_pricing.manage`, `chat.view`, ...). |

### `franchise_modules` — handled per the brief's explicit instruction ("handle existing franchise_modules safely... do not leave two competing activation systems")

Left completely untouched: same table, same columns, same rows, still written by `Franchises\Manage` and `FranchiseObserver` exactly as before this phase (zero behavior change to that existing, tested screen). It is not read by any new code from this phase forward — `module_activations` is now the sole system anything *decides* against. Its other 7 boolean columns (food/parcel/taxi/grocery/pharmacy/commerce/bookings) were deliberately NOT backfilled into `module_activations`: every one of those modules is `is_implemented=false`, so an explicit "off" row and an absent row are behaviorally identical under the new resolver — backfilling them would manufacture rows with zero observable effect. If a franchise's `franchise_modules` row has a real historical `true` for one of those columns, that intent is preserved, unread, in the frozen legacy table, and can be manually re-applied via the new Modules screen the day that module actually ships.

### `App\Services\ModuleActivationService` — the single authoritative resolver

- `isActive(string $moduleCode, array $scope): bool` — two gates: (1) `Module.is_implemented` must be true, unconditionally; (2) walks the cascade `zone → franchise → city → country` (same order/reasoning as `Setting::SCOPE_ORDER` — zone outranks franchise because `zones.franchise_id` makes Zone the more specific child), returning the first explicit row found. If nothing found anywhere in the chain, `service` defaults active (the one documented legacy-compatibility exception — not a precedent for any future module, every other module defaults inactive with no row).
- `resolvedFrom()` — reports exactly which level (if any) is deciding the state, for the admin screen's "set here" vs "inherited" display.
- `setActive()` — writes one row at one exact scope; validates `scope_type` against the real 4 levels.

### `App\Livewire\Modules\Manage` (`/admin/modules`, `modules.manage` permission)

Pick a scope level (Country/City/Zone/Franchise) and an entity; see all 9 registered modules, their effective state (resolved through the whole cascade, not just the exact row at that level), and where that state comes from. Only `is_implemented=true` modules (today: only `service`) can actually be toggled — every other module's row shows "Not available" with no click target at all, both because the resolver would ignore it and because the mission brief's own "unimplemented module must never appear as usable" applies to the UI, not just the backend.

### Real enforcement — closing the exact "silent-toggle risk" the audit named

`CreateBookingAction::execute()` — the one place every booking is created (customer app, admin panel, or a Tinker test alike, per its own pre-existing docblock) — now resolves the full `{zone,franchise,city,country}_id` scope from the target `Franchise` and calls `ModuleActivationService::isActive('service', ...)` before creating anything. A false result throws `App\Exceptions\ModuleNotActiveException` and the booking is never created. This is the one change that makes the mechanism real rather than a second unread flag next to the first one.

### `App\Observers\FranchiseObserver`

Every newly-created franchise now gets a real, explicit `module_activations` row for `service=true` at creation, alongside its existing (unchanged) `FranchiseModule::create()` call — so no franchise created from this point forward ever depends on the legacy default at all; it has a genuine row from day one.

---

## Tests

30 new tests, 3 new files under `tests/Feature/Modules/`:

- **`ModuleActivationServiceTest`** (10 tests) — direct resolver coverage: unimplemented module never active despite an explicit "on" row; `service` defaults active with no row anywhere; a future (temporarily-flagged-implemented) module defaults inactive with no row; zone outranks franchise; country-level off is not silently overridden by an unset city/franchise/zone; city-level on is honored when nothing more specific exists; `resolvedFrom()` correctly distinguishes an explicit row from the legacy default; `setActive()` rejects an invalid scope type.
- **`ModuleActivationEnforcementTest`** (4 tests) — proves the real choke point: booking creation still succeeds by default (zero behavior change for the existing Service vertical); is blocked when an admin deactivates `service` at franchise level; is blocked when deactivated at country level with no franchise override; a franchise-level reactivation correctly overrides a country-level deactivation.
- **`ModulesScreenAuthorizationTest`** (6 tests) — permission-denied/allowed, super_admin bypass, toggling an unimplemented module is a true no-op (no row written), toggling `service` writes a real row, and the mutating `toggle()` action itself re-checks the permission (defense-in-depth, same convention as `Chat\Manage::selectBooking()`).

Full suite before this phase: 778/778 passing, 1,799 assertions. Full suite after: **798/798 passing, 1,825 assertions, 0 failures/errors/warnings** — verified via `php artisan test`, both the isolated `tests/Feature/Modules` run and the full repository suite.

---

## Data migration safety

- Every migration in this phase is additive (one new nullable-with-default column, two new tables, one new permission row) — no existing table's existing columns were altered, no existing row was deleted or rewritten.
- `franchise_modules` — completely untouched, per the brief's explicit instruction. Real historical/admin-configured data preserved exactly.
- The backfill migration (`2026_08_16_902000`) only ever inserts (`insertOrIgnore`) — it cannot destroy or overwrite any existing row, and its `down()` only removes rows it itself would have created (`module_id = service AND scope_type = franchise`), safe to roll back.
- Rollback of all four new migrations was not additionally stress-tested against a populated database this phase (same honest limitation this codebase's own prior migrations have noted for themselves), but each `down()` only drops a column/table this phase itself introduced or deletes rows this phase itself would have inserted — no cross-table cascade risk.
- **Data risk classification: LOW**, matching `PHASE_22_PLATFORM_CAPABILITY_RECOVERY_AUDIT.md §8`'s own prediction for exactly this piece of work.

## Production status

Not deployed. Not migrated on production. Production remains at `ba0635a`, `APP_DEBUG=false`, untouched. Every migration above has only ever run against the local/test SQLite database in this session.

## Remaining risk / honest scope limits

- The Modules admin screen's entity dropdowns (Country/City/Zone/Franchise) are simple `<select>` lists with no search/pagination — fine at today's near-zero-franchise-count production data volume (`PROJECT_CURRENT_STATE.md §2`: 4 franchises, 3 zones), a real usability gap the moment that count grows into the hundreds. Not fixed here — out of this phase's scope, logged for whoever eventually revisits this screen.
- No bulk-activation action exists (e.g., "activate Parcel for every franchise in Country X at once") — each row is one scope × one module × one click. Deliberately minimal for this foundational phase; the mission's own Phase 22.4+ per-vertical work is a more natural place to learn what bulk-activation shape is actually needed, rather than guessing now.
- `ModuleActivationService::isActive()` is not cached (unlike `Setting::get()`, which caches forever per resolved key) — every `CreateBookingAction::execute()` call now performs up to 4 additional `SELECT`s. At today's booking volume (near-zero production traffic, confirmed by `PROJECT_CURRENT_STATE.md §2`) this is not a real performance concern; worth revisiting with the same caching pattern `Setting` already uses if/when it becomes one.
- This phase deliberately does not attempt to reconcile `franchise_modules`' 8-vs-9 column drift (missing `car_rental`) flagged in `app/Support/Modules.php`'s own docblock — that table is frozen, not extended, per this phase's own design (see "franchise_modules" section above).

## Next step

Per `PHASE_22_PLATFORM_CAPABILITY_RECOVERY_AUDIT.md §17`, the next architecturally-blocking item is the Order Engine decision (Phase 22.2) — `bookings.service_id`'s non-polymorphism, which every subsequent vertical (Parcel onward) is blocked on regardless of module-activation readiness.
