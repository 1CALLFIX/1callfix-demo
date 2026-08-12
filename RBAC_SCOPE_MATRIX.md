# RBAC Scope Matrix

Derived from `AuthorizationService`, the `role_assignments` migration, the permission seeder, and this session's + the prior session's RBAC test suite (50 tests total exercise this directly). Scope mechanics are fully tested; the 7 system roles are tested via purpose-built single-permission test roles rather than each bundled role individually (see `FINAL_SYSTEM_TEST_MATRIX.md`).

## Scope dimensions (6 total, `role_assignments.scope_type` enum)

| Scope type | `scope_id` refers to | Inheritance | Tested this program |
|---|---|---|---|
| `global` | none (null) | Covers everything — `scopeCovers()` returns `true` unconditionally for a `global`-type assignment | Yes — every module's Super Admin bypass test |
| `country` | `countries.id` | No automatic inheritance to city/zone/franchise — `AuthorizationService::can()` only matches when the REQUESTED scope array's `country_id` key equals the assignment's `scope_id`; a country-level grant only covers actions whose scope array actually includes a `country_id` (Zones does; Categories doesn't, since catalog data carries no country column) | Only incidentally (constructed, not a dedicated positive-case test) |
| `city` | `cities.id` | Same non-inheriting model | Yes — `ZonesAuthorizationTest::test_city_scoped_grant_covers_a_zone_in_a_franchise_within_that_city` + the cross-city denial case |
| `zone` | `zones.id` | Same | Yes — `BookingCreationAuthorizationTest`'s zone-scoped `bookings.create` tests (allow in-zone, deny cross-zone) |
| `franchise` | `franchises.id` | Same | Yes — extensively: `ZonesAuthorizationTest`, `BannersAuthorizationTest`, `RolesEscalationTest` |
| `module` | presumably a `modules.id` (the `franchise_modules`/vertical-toggle concept) | Same | **Not exercised by any test this program** — no permission in the current catalog is actually scoped by module in practice |

## How `AuthorizationService::can()` actually resolves scope (read from source, not assumed)

1. `user.role === 'super_admin'` → `true` immediately, no `role_assignments` lookup at all (backward-compat fast path, predates the RBAC system).
2. Otherwise, iterate every `role_assignments` row for the user. For each: does the assigned Role carry the requested permission? If yes, does `scopeCovers(assignment.scope_type, assignment.scope_id, $requestedScope)` return true?
3. `scopeCovers()`: `global` → always true. Anything else → `$requestedScope["{scope_type}_id"]` must be present AND equal the assignment's `scope_id`. **No cross-dimension inference** — a `country`-scoped assignment does NOT automatically cover a `city`/`zone`/`franchise` scope request; the caller must explicitly include a `country_id` key in the scope array it builds for that to match (which is exactly why `Zones\Manage`/`Bookings\Show`/`Bookings\Index` build a full `['zone_id'=>.., 'franchise_id'=>.., 'city_id'=>.., 'country_id'=>..]` array from the target resource's actual geography chain — see `bookingScope()`/`zoneScope()`/`franchiseScope()` across those files).
4. Grants are **additive** across multiple assignments — holding a `support` role globally AND a `zone_admin` role for one specific zone means both apply; there is no "most specific wins" override logic (that's `Setting::get()`'s cascade model, deliberately different and not reused here).

## Permission × scope-applicability matrix (which permissions have a real, checkable scope vs. global-only)

| Permission | Has a real per-record scope? | Scope built from | Enforced (this program) |
|---|---|---|---|
| `zones.manage` | Yes — franchise → city → country | The target franchise's `city_id`/`country_id` columns | Yes |
| `services.manage` | **No** — `services` carries no franchise/geography column | N/A — global-only | Yes |
| `categories.manage` (governs subcategories too) | **No** — same reason | N/A — global-only | Yes |
| `banners.manage` | Yes, but different shape — the banner's OWN `franchise_id` is a *targeting* axis (null = runs everywhere), not just an ownership scope | The banner's `franchise_id` directly | Yes |
| `cms.manage` | **No** — `content_pages`/`faqs` carry no geography column | N/A — global-only | Yes |
| `bookings.create` | Yes — zone → franchise → city → country | The target zone's franchise's `city_id`/`country_id` | Yes (createBooking/addNewAddress); `createCustomer` uses `canAnywhere()` instead since no zone exists yet at that point in the flow |
| `bookings.reassign` / `bookings.cancel` | Yes | The booking's own `zone_id`/`franchise_id`/franchise's `city_id`/`country_id` | Yes (pre-existing, `Bookings\Show`) |
| `roles.manage` | Yes | The scope the assignment being granted/revoked targets | Yes — this session locked in the privilege-escalation fix with 6 tests |
| `geography.manage` | **No** (deliberately — creating a country/city is treated as a rarer, higher-level action than managing a franchise within one) | N/A — global-only | Pre-existing, not touched this program |
| `franchises.manage` | Partial — only checked on `save()` (create), not on `update()`/`toggleStatus()`/`delete()` | Country | **Gap, not in this program's scope, flagged for the record**: `Franchises\Manage`'s edit/toggle/delete actions have no permission check at all — found during this program's RBAC read-through, not fixed here (out of the originally-scoped 7 gaps, and fixing it wasn't requested) |

## Cross-scope denial — verified both directions

Every scoped permission above with a real per-record scope has a passing automated test proving **both**: the correct scope is allowed, AND an adjacent/wrong scope of the same type is denied (not just "no scope at all is denied" — the harder, more meaningful case). Examples: `ZonesAuthorizationTest::test_user_scoped_to_a_different_franchise_is_denied`, `test_city_scoped_grant_does_not_cover_a_different_city`, `BannersAuthorizationTest::test_franchise_scoped_grant_cannot_create_banner_for_a_different_franchise`, `BookingCreationAuthorizationTest::test_zone_scoped_grant_does_not_cover_a_different_zone`.

## Known gap in this document

`franchises.manage`'s partial enforcement (found above) and the `module` scope dimension having zero real consumers are both worth a follow-up pass — recorded here rather than silently omitted, consistent with this program's own "no unexplained gaps" rule.
