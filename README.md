# 1CallFix

A franchise-ready, multi-vertical home-services marketplace backend + Admin Panel, built for 1CallFix Solutions Pvt Ltd. Laravel 13 + Livewire 4.

**For the authoritative, up-to-date description of what's actually built, what's tested, and what's left — read [`PROJECT_CURRENT_STATE.md`](PROJECT_CURRENT_STATE.md) first.** This README is a setup/reference guide; that document is the source of truth on project state.

## What this is

One codebase, multiple verticals (Service, Parcel, Taxi, Rental — Property/Vehicle/Equipment, Hotel/Stay, Marketplace — Ecommerce/Food/Grocery/Pharmacy — toggleable per franchise via `franchise_modules`, and per-geography via the Modules admin screen). **Service is the only vertical enabled by default** (`modules.is_implemented = true`); every other vertical is fully built, tested, and admin-manageable end-to-end, but ships with `modules.is_implemented = false` until an explicit business decision activates it — see `KNOWN_RISKS_AND_DECISIONS.md` for the current activation status of each. The booking pipeline — Customer → Dispatch → Provider/Worker → Completion → Commission → Wallet → Loyalty → Referral — is real, integrated, and covered by an automated regression suite (see Testing below).

## Tech stack

- **Laravel 13**, PHP 8.3+
- **Livewire 4** — the entire Admin Panel; no separate frontend build, Tailwind via CDN
- **MySQL** in production; automated tests run against **SQLite `:memory:`**
- **Sanctum** — API token auth for mobile clients
- **maatwebsite/excel** — catalog CSV/XLSX import-export
- Queue: `database` driver, Supervisor-managed worker in production
- **Razorpay** — payments (test mode)

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

For local development, `.env.example` already defaults to `DB_CONNECTION=sqlite` — no MySQL setup required to get the app booting. Point `DB_*` at a real MySQL instance for anything beyond quick local checks.

## Environment requirements

- PHP 8.3+, `pdo_sqlite` + `sqlite3` extensions (required for the test suite), MySQL for production-like use
- Composer 2.x
- A queue worker running (`php artisan queue:work`) for dispatch (`ServiceMatchingJob`) and notification delivery to actually process

## Migrations

Standard Laravel migrations, `database/migrations/`. All migrations are additive by convention — historical migrations are not modified except where a migration's SQL wasn't portable across drivers (see `PROJECT_CURRENT_STATE.md` §16 for the one case where this happened and why). Every migration has a working `down()` — verify with:

```bash
php artisan migrate
php artisan migrate:rollback --step=N
php artisan migrate
```

## Testing

```bash
php artisan test
```

Runs against SQLite `:memory:` (configured in `phpunit.xml`) — never against your local MySQL database or production. The suite covers RBAC enforcement and scope resolution, a privilege-escalation regression, commission idempotency, and Worker delegation authorization boundaries. See `PROJECT_CURRENT_STATE.md` §19 for exactly what's covered and what isn't yet.

## Queues

```bash
php artisan queue:work
```

`ServiceMatchingJob` (the dispatch engine) is a self-requeuing queued job — it needs a live worker to make any progress. In production this runs under Supervisor.

## Roles

Seven system roles, scope-aware (`global/country/city/zone/module/franchise`): `super_admin`, `country_admin`, `city_admin`, `zone_admin`, `franchise_owner`, `operator`, `support`. See `AuthorizationService` and the Roles & Permissions admin screen. Full detail in `PROJECT_CURRENT_STATE.md` §13.

## Current modules (Admin Panel)

Dashboard, Bookings, Providers, Workers, Franchises, Franchise Pricing, Zones, Geography, Categories, Subcategories, Services, Customers, Roles & Permissions, Wallet Ledger, Loyalty & Referrals, Commissions, Payments, Payouts, Banners, Badges, Flash Sales, Performance Campaigns, CMS, Notification Center, Plans, Subscriptions, Chat, Operations (health/troubleshoot), KYC, Modules (per-geography vertical activation), Settings.

Per-vertical (built and admin-manageable, activation status per `KNOWN_RISKS_AND_DECISIONS.md`): Parcel Orders, Taxi Rides, Properties + Property Reservations, Vehicles + Equipment + Rental Reservations, Accommodations + Hotel Reservations, Stores + Marketplace Categories + Products + Add-Ons + Marketplace Orders.

## Deployment notes

Production deployment is a manual, controlled operation — not automated from this repository. Never run `php artisan migrate` against production without first checking it against a disposable database. See `PROJECT_CURRENT_STATE.md` §2 and §18 for current production state.

## Roadmap

Parcel, Taxi, Rental (Property/Vehicle/Equipment), Hotel/Stay, and Marketplace (Ecommerce/Food/Grocery/Pharmacy) are all now built, tested, and admin-manageable — none is a "next planned" item anymore. Each ships with `modules.is_implemented = false` (fully inert in every real environment) pending an explicit business decision on activation order/commercial policy (fees, commissions, deposits) — see `KNOWN_RISKS_AND_DECISIONS.md` for the current per-vertical status. The Worker/Rider foundation, polymorphic dispatch primitives, WalletService, and RBAC that made this possible are documented in `PROJECT_CURRENT_STATE.md` §23.
