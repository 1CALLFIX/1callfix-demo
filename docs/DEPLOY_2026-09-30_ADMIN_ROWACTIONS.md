# Deploy: admin tabs, row actions and archive (2026-09-30)

REF 1CF-ADMIN-TABS-001 / 1CF-ADMIN-ROWACTIONS-001. Branch `release/earn3-integration`, up to commit `a117506`.

Already live on production (do not repeat): sitemap/robots, root-level CMS pages, tab rows (`9faab5e`),
Customers/Providers/Workers row actions (`1dca986`).

This bundle adds **money-record archive** (`29680f0`) and **catalogue/vertical tabs + archive** (`a117506`),
which need **two migrations** and touch a payment-critical path (Razorpay webhook / confirm lookups).

## What is in the bundle

`deploy_bundle_2026-09-30.tar.gz` (project root, 47 runtime files, paths relative to the app root):

- migrations: `2026_09_30_000300_add_soft_deletes_to_payments_payouts_subscriptions.php`,
  `2026_09_30_000400_add_soft_deletes_to_catalog_config_tables.php`
- models (soft deletes): Payment, Payout, Subscription, Badge, PerformanceCampaign, MarketplaceCategory, AddOn
- payment paths: `RazorpayWebhookHandler`, `API/PaymentController` (an archived order that later gets paid is revived)
- `Services/Qa/QaCleaner` (force-deletes payments/subscriptions so QA cleanup still removes rows)
- Livewire screens + views for Payments, Payouts, Subscriptions, Properties, Vehicles, Equipment, Accommodations,
  Stores, Products, Plans, Add-ons, Badges, Marketplace categories, Performance campaigns, and the six order/reservation
  screens (Parcel, Taxi, Property, Rental, Hotel, Marketplace)
- `Livewire/Concerns/HasRowArchive` (shared filter + archive logic)

## Steps (on the server, in the app root `/home/1callfix.com/public_html/api`)

1. **Backup the database** (any dump you trust; the Clear Data tool's mysqldump also works).
2. Upload `deploy_bundle_2026-09-30.tar.gz` to the app root.
3. Short maintenance window, because the new code reads `deleted_at` columns the moment it is live:
   ```
   php artisan down
   tar xzf deploy_bundle_2026-09-30.tar.gz
   php artisan migrate --force
   php artisan optimize:clear
   php artisan up
   ```
   Expect `migrate` to list both `..._000300_...` and `..._000400_...` (the first file may already be on the server;
   that is fine — migrate only runs it once). Both only add a nullable `deleted_at` column; nothing is rewritten.
4. No asset rebuild is needed (no CSS/JS changed in this bundle).

## Smoke test (Admin, signed in as Super Admin)

- Payments: tabs All | Online | Wallet | Cash; status tabs incl. **Archived**; a failed or >24h-pending row shows **Delete**;
  a captured/refunded row shows no Delete.
- Payouts (failed rows) and Subscriptions (unpaid/ended rows): Delete / Restore work; Archived tab lists them.
- Properties, Vehicles, Equipment, Accommodations, Stores, Products, Plans, Add-ons, Badges, Marketplace categories,
  Performance campaigns: All | Active | Inactive | Archived tabs.
- Parcel / Taxi / Property / Rental / Hotel / Marketplace order screens: status tab rows start at **All**.
- Place a small test payment or open any booking payment page to confirm checkout still works (unchanged code path,
  but the confirm/webhook lookup was touched).

## Rules to know

- **Delete is reversible**: it archives (`deleted_at`). Restore from the Archived tab.
- **Permanent delete = Super Admin only**, only for an already-archived row, refused if other records still use it.
- Never deletable: captured/refunded payments, paid payouts, active subscriptions, wallet ledger, commissions, orders.
- Guards: plans with subscribers, marketplace categories with sub-categories/products, badges that were assigned and
  campaigns with participants cannot be archived.
- Every archive / restore / permanent delete is written to the activity log.

## Rollback

```
php artisan down
php artisan migrate:rollback --step=2     # drops the two deleted_at migrations (archived rows become visible again)
# restore the previous files from your backup or `git checkout` of 1dca986 for the touched paths
php artisan optimize:clear
php artisan up
```
