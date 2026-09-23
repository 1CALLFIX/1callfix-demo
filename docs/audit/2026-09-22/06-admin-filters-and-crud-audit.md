# 06 — Admin Filters, Universal "All" Tabs, and CRUD/Audit Controls

**Ref:** `1CF-COMPREHENSIVE-AUDIT-20260922-1110` · Read-only, no code changed.

**Method note:** the admin surface is large — 45 distinct list/manage Livewire components across 48
top-level `app/Livewire/*` directories (excluding the `Customer/` and `Provider/` self-service
areas). This pass used a full inventory + targeted grep sweep (filter properties, `->delete()`
call sites, `SoftDeletes` trait usage) rather than a full manual read of every component. Rows
marked "sampled" were spot-verified by reading the actual file; rows marked "inventory-only" were
classified from the grep sweep and file listing alone and need a direct read before the roadmap
finalizes them. This distinction matters per the brief's own rule against presenting inference as
fact.

## 1. Filter / "All tabs" inventory

Full list of the 45 admin Manage/Index components, and whether a filter property was found
(`grep` for `public string $statusFilter|typeFilter|filter|tab|activeTab`):

| Section | Component | Filter property found | Notes |
|---|---|---|---|
| Users (all) | `AllUsers/Index.php` | Yes | Matches brief's exact example (`[All][Users][Providers][Drivers]`) |
| Customers | `Customers/Index.php` | Yes | |
| Providers | `Providers/Index.php` | Yes | |
| Drivers | `Drivers/Index.php` | No (inventory-only) | Likely a subset view of Providers/Workers — needs direct check for whether it needs its own filter or inherits scope |
| Workers | `Workers/Index.php` | Yes | |
| Bookings | `Bookings/Index.php` | Yes | `statusFilter`, single-select (not multi-tab) — see doc 05 for the urgency-tab gap specifically |
| Commissions | `Commissions/Index.php` | Yes | |
| Payments | `Payments/Index.php` | Yes | Matches brief's exact example (`[All][Cash][Wallet][Online]`) — **sampled**, confirmed present |
| Payouts | `Payouts/Manage.php` | No (inventory-only) | Payout status (pending/processing/paid/failed) is an obvious filter candidate — flagged gap pending direct read |
| Wallet ledger | `WalletLedger/Index.php` | Yes | |
| Loyalty | `Loyalty/Index.php` | Yes | |
| Subscriptions (memberships) | `Subscriptions/Index.php` | Yes | |
| Plans | `Plans/Manage.php` | No (inventory-only) | Plan catalog is typically small; may not need a status filter — needs semantic judgment call, not an automatic gap |
| Services | `Services/Manage.php` | Yes | |
| Categories | `Categories/Manage.php` | Yes | **Sampled** |
| Subcategories | `Subcategories/Manage.php` | Yes | |
| Franchises | `Franchises/Manage.php` | Yes | |
| Franchise pricing | `FranchisePricing/Manage.php` | No (inventory-only) | |
| Zones | `Zones/Manage.php` | Yes | **Sampled** |
| Geography (Country/City) | `Geography/Manage.php` | No (inventory-only) | Reference data, likely low priority for filters |
| Banners | `Banners/Manage.php` | Yes | Matches brief's exact example (`[All][Top][Mid]`) — needs direct check that the *type* tab (not just status) exists |
| Flash sales | `FlashSales/Manage.php` | No (inventory-only) | |
| Marketplace categories | `MarketplaceCategories/Manage.php` | No (inventory-only) | |
| Marketplace orders | `MarketplaceOrders/Manage.php` | Yes | |
| Products | `Products/Manage.php` | No (inventory-only) | Active/inactive/out-of-stock filter is an obvious candidate |
| Stores | `Stores/Manage.php` | No (inventory-only) | |
| Accommodations (property rental) | `Accommodations/Manage.php` | No (inventory-only) | |
| Property reservations | `PropertyReservations/Manage.php` | Yes | |
| Hotel reservations | `HotelReservations/Manage.php` | Yes | |
| Rental reservations | `RentalReservations/Manage.php` | Yes | |
| Vehicles | `Vehicles/Manage.php` | No (inventory-only) | |
| Equipment | `Equipment/Manage.php` | No (inventory-only) | |
| Taxi rides | `TaxiRides/Manage.php` | Yes | |
| Parcel orders | `ParcelOrders/Manage.php` | Yes | |
| Add-ons | `AddOns/Manage.php` | No (inventory-only) | |
| Badges | `Badges/Manage.php` | No (inventory-only) | |
| Performance campaigns | `PerformanceCampaigns/Manage.php` | No (inventory-only) | |
| Payment gateways | `PaymentGateways/Manage.php` | No (inventory-only) | Config screen, likely doesn't need a status filter — semantic judgment |
| Notification center | `NotificationCenter/Manage.php` | No (inventory-only) | |
| CMS (content pages/FAQ) | `Cms/Manage.php` | No (inventory-only) | Published/draft filter is an obvious candidate |
| Chat | `Chat/Manage.php` | No (inventory-only) | |
| Roles | `Roles/Manage.php` | No (inventory-only) | Reference data, low priority |
| Modules | `Modules/Manage.php` | No (inventory-only) | On/off toggle screen, likely doesn't need tabs |
| Settings | `Settings/Manage.php` | Yes | |
| **Reviews / ratings** | **none found** | — | **Missing entirely** — see §2 |
| Kyc (support/review) | `Kyc/SupportRequests.php` | Not checked this pass | Business explicitly calls out `[All][Pending Review][Approved][Rejected]` for provider review — likely lives here or on `Providers/Show.php`'s KYC panel; needs direct check |

**Reading this table:** "No (inventory-only)" is **not** a confirmed gap — several of these
(Plans, Geography, Roles, Modules, Payment Gateways) are small reference/config screens where a
filter genuinely may not be warranted, exactly as the brief cautions against blindly adding tabs.
Each "No" row needs a five-minute direct read before the roadmap commits to building a filter for
it — the roadmap in doc 12 schedules that pass, it is not done here.

## 2. Confirmed missing section: Reviews/Ratings

**Status: Missing (confirmed, not inferred).** A `Review` model exists
(`app/Models/Review.php`, backed by `ReviewService`, used on the customer web for post-completion
reviews per prior session work), but **no admin Livewire component of any kind exists to list,
moderate, approve, or reject reviews** — confirmed by an exhaustive `find` across `app/Livewire`
for anything matching `*review*`, which returned zero admin-side results (only the customer-facing
review submission flow exists). This is the exact gap the brief's own example calls out
(`[All][Pending Review][Approved][Rejected]`) — not a hypothetical, a literal missing screen.

## 3. CRUD / destructive-action safety audit (sampled, not exhaustive)

Grepped every `->delete()` / `::destroy()` call site across all `Manage.php`/`Index.php`/`Show.php`
admin components (22 call sites found). None touch a financial-record model (`Payment`,
`Commission`, `Payout`, `ProviderCommissionReceivable`, `BookingStatusHistory`) — **the codebase
already respects the "never hard-delete financial/audit records" rule** the brief requires; this
appears to be an established convention, not an accident.

Of the models that *are* deletable from an admin screen, three were sampled in full:

| Model | `SoftDeletes`? | Guarded before delete? | Evidence |
|---|---|---|---|
| `Provider` | Yes | Yes — confirms technician/online-status warnings, soft-deletes ("hidden from dispatch... history stays intact") | `Providers/Show.php:207-224` |
| `Zone` | **No** | Yes — hard blocks delete if providers/bookings/addresses are attached, suggests deactivating instead | `Zones/Manage.php:296-338` |
| `ServiceCategory` | **No** | Yes — hard blocks delete if services are attached | `Categories/Manage.php:270-308` |

**Finding:** `Zone`, `ServiceCategory`, `Subcategory`, `Country`, `City` have no `SoftDeletes` trait
(confirmed by direct grep against each model file), meaning a delete on an *unblocked* row (no
attached bookings/services) is a genuine hard delete with no restore path. The two sampled cases
mitigate this well with a pre-delete usage check, but:
- **Not yet verified** whether `Subcategory`, `Country`, and `City`'s delete actions have the same
  guard (their call sites were found in the grep sweep but not read this pass).
- Even a *correctly blocked* hard-delete model has no "restore" concept if the guard is ever wrong
  or bypassed later (e.g. a future code change that removes the guard) — `SoftDeletes` would be a
  strictly safer default for reference data that historical financial/booking records can point to.

## 4. Authorization matrix

**Status: Unverified this pass.** The brief asks for a full CRUD × role matrix (Super Admin,
Country/City/Zone Admin, Manager/operator, Support, Marketing, Provider, Customer). Every sampled
delete action above does check a permission (`hasPermission('categories.manage')`,
`canWithRestrictedScope(..., 'zones.manage', ...)`, etc.) before acting, which is the right pattern
— but a systematic per-resource × per-role matrix was not built in this pass; it requires reading
the permission seed data (`database/seeders` permission definitions) against every admin action,
which is scoped into doc 12's roadmap as its own investigation phase rather than compressed into
this already-large pass.

## 5. Required changes (roadmap input — not implemented in this pass)

| # | Change | Risk |
|---|---|---|
| 1 | Build an admin Reviews/Ratings screen with `[All][Pending][Approved][Rejected]` — confirmed clean gap, no existing code to conflict with | Low |
| 2 | Direct-read the 22 "inventory-only" components in §1, decide filter-vs-no-filter per section, implement where warranted | Low per section, moderate in aggregate |
| 3 | Add `SoftDeletes` to `Zone`, `ServiceCategory`, `Subcategory`, `Country`, `City` (additive migration, `deleted_at` column) so a delete — even a correctly-guarded one today — is always recoverable | Low-medium — additive column, but every query touching these models needs a check that global scopes behave as expected |
| 4 | Verify `Subcategories/Manage.php` and `Geography/Manage.php`'s delete guards match the Zone/Category pattern; fix if not | Low |
| 5 | Build the full role × resource authorization matrix as its own documentation deliverable (too large to fold into this pass) | — (documentation only) |

## 6. Open items requiring direct verification before the roadmap is final

- Whether `Kyc/SupportRequests.php` already *is* the provider-review moderation screen the brief's
  example describes (plausible given its name) — needs a direct read.
- Whether `Payouts/Manage.php` already filters by status internally (e.g. via query string) despite
  no `public string` filter property matching the grep pattern used (a differently-named property
  would be missed by this sweep).
