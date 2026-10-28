# Release 2026-10-26 — Admin → Partners; each city approves its own partners

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `073b7f9` (also carries `559284d`, riders join through /partners, which was not deployed separately; its note is `2026-10-25-rider-partner-signup.md`) |
| Full test suite | 4094 of 4095 pass; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (new classes on the Partners page and lists) |
| Version | `VERSION` file bumped to `2026.10.26.1` |

## What's in it
Owner decision 2026-10-26: one admin page for every partner, and each city reviews its own partners so HQ does not
have to review every city.
- **Admin → Users → Partners** replaces Workers and the empty Drivers page. Two tabs: **People** (riders, drivers,
  helpers: the existing worker list) and **Businesses** (service pros, restaurants, grocery shops, online shops,
  pharmacies, hotels, rentals: the existing provider list). A type dropdown inside each tab; city, franchise and zone
  dropdowns. Each tab opens on **Needs review** (oldest wait first, wait in hours: amber from 24 h, red from 48 h),
  with a count badge; "All partners" is one click away. Rows open the existing review screens.
- `/admin/workers` and `/admin/drivers` redirect (301, query kept) to the People tab. Admin → Providers stays (import,
  export, online filter).
- **Franchise Owner** can now approve partner documents and partners for their own franchise only (permissions
  `providers.review_kyc`, `workers.view`, `workers.review_kyc`). This also lets them use "Add parcel rider" (walk-ins)
  and activate / archive their own franchise's workers, as those were already behind `workers.review_kyc`.
  Operator is unchanged. Super Admin can untick these, or give them to another role, in Roles & Permissions. To take
  approval away from one city only: make a copy of Franchise Owner without them (+ New role) and assign that copy to
  that city's owner.
- **Security fix:** the Stores, Hotels, Properties, Vehicles and Equipment screens listed every provider on the
  platform as the owner choice and accepted any provider when saving. Both are now limited to the user's own area.
  Not exploitable on production today (only Super Admin holds those permissions), but it would have been as soon as
  a franchise role got one.
- **A service provider whose documents are not approved can no longer switch online** (same as riders). Dispatch
  already skipped them.
- Unchanged: the review actions, per-document approve / reject (reason required, reviewer recorded), Approve Worker
  rules, the required document list, the /partners join flow, all payment, cash dues, commission and settlement rules.

## Step 5 — migrations that must be pending (exactly these)
- `2026_10_26_100000_franchise_owner_reviews_own_partners` (data only: adds 3 permissions to Franchise Owner)

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Admin → Users shows **Partners**, not Workers or Drivers. `/admin/workers` lands on Partners → People.
- People and Businesses tabs open on Needs review; type, city, franchise and zone dropdowns filter.
- Open a pending partner: per-document Approve / Reject still works.

## Test logins and walk-through (thumb rule)
`php artisan test-accounts create --password='<your test password>' --franchise=<id>` (already makes the franchise
owner `test.franchise@1callfix.test` and the rider `test.rider@1callfix.test`). Sign in at /admin/login as the
franchise owner: Partners shows only that franchise's partners; open the test rider and the review screen. The
operator login `test.operator@1callfix.test` does not see Partners (bookings only).

## Admin settings to do after deploy (owner)
- Assign a real Franchise Owner per city in Roles & Permissions (none exist on production yet).
