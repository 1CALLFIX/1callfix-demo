# 07 — Serial Numbers in Admin Tables Audit

**Ref:** `1CF-COMPREHENSIVE-AUDIT-20260922-1110` · Read-only, no code changed.

## 1. Method

Swept `resources/views/livewire/**` for the patterns that would indicate a serial-number column:
`firstItem()` (Laravel paginator's "index of the first row on this page," the correct base for a
pagination-safe serial number), `loop->index`, `S.No`/`Sr. No` labels. This is a targeted regex
sweep, not an exhaustive per-file read — flagged as inventory-level evidence.

## 2. Finding

**7 of ~45 admin table views** use `firstItem()`-based numbering:

- `resources/views/livewire/services/manage.blade.php`
- `resources/views/livewire/banners/manage.blade.php`
- `resources/views/livewire/subcategories/manage.blade.php`
- `resources/views/livewire/categories/manage.blade.php`
- `resources/views/livewire/franchises/manage.blade.php`
- `resources/views/livewire/zones/manage.blade.php`
- `resources/views/livewire/customer/search-bar.blade.php` (customer-facing, not an admin table —
  false positive for this audit's purpose, excluded from the count below)

So **6 genuine admin tables** have a serial number column.

### Formula check (sampled)

`categories/manage.blade.php:185` and `zones/manage.blade.php:157`:
```blade
{{ $categories->firstItem() + $i }}
```
This is exactly the pagination-correct formula the business specified —
`((current_page - 1) × per_page) + row_index`, since Laravel's `firstItem()` already equals
`(current_page - 1) × per_page + 1`, and `$i` here is a 0-based loop index. **Implemented and
verified** for these two; the other four (`services`, `banners`, `subcategories`, `franchises`) use
the same `firstItem() + $i` call and are treated as the same pattern, sampled at the two above.

## 3. Gap

**~38 of ~45 admin table views have no serial number column at all** — no matching pattern found
across the rest of `resources/views/livewire/**`. This is the dominant state, not the exception.
Tables confirmed (from doc 06's inventory) to be missing one include, at minimum: Bookings,
Commissions, Payments, Payouts, Wallet Ledger, Loyalty, Subscriptions, Providers, Customers,
Workers, Drivers, all reservation types (Property/Hotel/Rental), Marketplace Orders, Products,
Stores, Taxi Rides, Parcel Orders — i.e. every high-traffic operational table in the admin panel.

No case of a serial number being confused with the primary key or a public booking/order reference
was found in the 6 tables that do have one (they're rendered as a separate leading column, distinct
from any `#{{ booking.code }}`-style reference column elsewhere in the same row) — so where the
pattern exists, it's implemented correctly; it's simply not applied broadly.

## 4. Required changes (roadmap input)

| # | Change | Risk |
|---|---|---|
| 1 | Add a `{{ $collection->firstItem() + $i }}` leading column to the ~38 tables that lack one, reusing the exact formula already proven correct in the 6 that have it | Low — purely additive, no logic change, no schema change |
| 2 | Confirm the `$i` loop-index variable is consistently a 0-based `@foreach ($rows as $i => $row)` (not `$loop->index` vs `$loop->iteration` mixed inconsistently) before templating this across all tables, so the rollout is mechanically identical everywhere | Low |

This is a small, low-risk, high-volume mechanical change — a good candidate for a single
low-risk implementation phase in the roadmap (doc 12) rather than being bundled with any
higher-risk financial or dispatch work.
