# Database Audit — 2026-08-21

**Read-only session.** Every command in this audit was `SELECT`/`SHOW`/`DESCRIBE`, Laravel's own `migrate:status` introspection, or a plain `GET` against public read endpoints. No `migrate`, no `tinker` write, no `UPDATE`/`DELETE`/`INSERT`, no `.env` change, no cron change was executed at any point. Scope: `/home/1callfix.com/public_html/api` on the VPS (`srv1422426.hstgr.cloud`), cross-referenced against the local repo (`1CALLFIX/1callfix-demo`). The sibling app at `public_html` (site root) was not touched.

---

## Plain-language summary

This is 1CallFix's real production database — `api.1callfix.com`, MySQL on a Hostinger VPS — and it is genuinely pre-launch: two internal admin accounts, four franchises, three zones, a handful of hand-entered (visibly test-quality) catalog rows, and **zero** bookings, payments, or commissions ever recorded. The database's schema is frozen at migration batch 32 (last applied 2026-08-11), while the application code deployed on that same server today is far ahead of it — 226 migration files exist in the codebase, 119 have run, **107 are pending** (not the ~180 originally guessed). Those 107 are not stray or unexplained: every one of them is present in the local `main` branch, every one traces to a real, dated commit between 2026-08-11 and 2026-08-18 (a continuous, documented build sequence — Plan Engine, Worker/RBAC foundation, KYC hardening, Badges/Flash Sales/Campaigns, Parcel/Taxi/Rental/Hotel/Marketplace verticals, Module Activation), and the project's own `KNOWN_RISKS_AND_DECISIONS.md`/`ROLLBACK_PLAN.md` already document this exact "migrations written and reversible, not yet applied" posture, including the three module-registry rename migrations by name. In other words: this looks like a real, deliberate, paused rollout, not an accident or a mystery.

What this audit found that **is** a new, concrete concern, not previously documented: the application code currently running in production already assumes several of those pending migrations have been applied, and at least two real, verifiable gaps exist today as a result. `modules.is_implemented` (the column that gates every vertical, including the currently-"live" Service vertical) doesn't exist yet on production — which means `ModuleActivationService::isActive()` currently returns `false` for *every* module, including Service, so real booking creation (`CreateBookingAction`, both the admin call-center flow and the new customer-facing `POST /api/bookings`) is being rejected with `ModuleNotActiveException` right now, in production, for anyone who tries it. Separately, `payment_webhook_logs` (used unconditionally, with no `try/catch`, by `PaymentController::webhook()`) doesn't exist yet either — a real live Razorpay webhook call today would 500 after `handleCaptured()`/`handleFailed()` already ran, not before. Both are currently invisible because there's been no real booking or payment traffic to trip them (0 bookings, 0 payments recorded), but they are real, present-tense bugs, not hypothetical future risk.

**What would need to be true before it's safe to run the pending migrations:** (1) a fresh `mysqldump` backup taken immediately before, exactly as `docs/DEPLOYMENT_RUNBOOK.md`/`docs/ROLLBACK_PLAN.md` already require for any migrating deploy; (2) a human decision on whether to run all 107 at once or in the documented phase order, given some are additive-only and some (the three `modules` table renames, the `commissions.booking_id`/`bookings.coupon_id` constraint additions) touch existing rows, even though the current row counts make that low-risk in practice; (3) explicit awareness that running them is also what *fixes* the two live gaps above, so delaying further has its own real cost, not just running them does. No migration was run, and no recommendation on timing/order is made here — that's flagged as a decision for after this document is read, per the audit's own brief.

---

## 1. Full table inventory (row counts)

Read via `SHOW TABLES` + `COUNT(*)` per table, live on `srv1422426.hstgr.cloud`, 2026-08-21:

| Table | Rows | | Table | Rows |
|---|---|---|---|---|
| activity_log | 0 | | loyalty_points | 0 |
| addresses | 0 | | migrations | 119 |
| banners | 2 | | modules | 9 |
| booking_extra_items | 0 | | notification_campaigns | 0 |
| booking_options | 0 | | notification_logs | 42 |
| booking_sequences | 5 | | notification_meetings | 0 |
| booking_status_history | 0 | | notification_templates | 0 |
| bookings | 0 | | notifications | 0 |
| business_accounts | 0 | | otps | 0 |
| business_locations | 0 | | partner_workers | 0 |
| cache | 443 | | payment_accounts | 0 |
| cache_locks | 0 | | payment_methods | 0 |
| cancellation_policies | 0 | | payments | 0 |
| cancellation_reasons | 0 | | payouts | 0 |
| chat_messages | 0 | | permission_role | 83 |
| cities | 4 | | permissions | 45 |
| commissions | 0 | | personal_access_tokens | 0 |
| content_pages | 0 | | plan_entitlements | 0 |
| coupon_usages | 0 | | plans | 0 |
| coupons | 0 | | protection_plans | 0 |
| countries | 1 | | provider_badges | 0 |
| dispatch_attempts | 0 | | provider_documents | 0 |
| entitlement_balances | 0 | | provider_subscriptions | 0 |
| failed_jobs | 1 | | providers | 0 |
| faqs | 0 | | push_notifications | 0 |
| field_worker_capabilities | 0 | | referrals | 0 |
| field_worker_documents | 0 | | reviews | 0 |
| field_workers | 0 | | role_assignments | 0 |
| franchise_applications | 0 | | roles | 7 |
| franchise_modules | 3 | | service_categories | 7 |
| franchise_payout_ledger | 0 | | service_option_groups | 0 |
| franchise_service_pricing | 0 | | service_options | 0 |
| franchises | 4 | | service_subcategories | 3 |
| job_batches | 0 | | services | 8 |
| jobs | 0 | | settings | 3 |
| | | | sos_alerts | 0 |
| | | | subscription_plans | 0 |
| | | | subscriptions | 0 |
| | | | usage_ledger | 0 |
| | | | user_protection_plans | 0 |
| | | | users | 2 |
| | | | wallet_transactions | 0 |
| | | | wallets | 0 |
| | | | zones | 3 |

**Total: 74 tables.** All but 15 are empty. Non-empty tables: `banners`(2), `booking_sequences`(5), `cache`(443, ephemeral Laravel cache rows, not business data), `cities`(4), `countries`(1), `failed_jobs`(1), `franchise_modules`(3, legacy/superseded table), `franchises`(4), `migrations`(119), `modules`(9), `notification_logs`(42), `permission_role`(83), `permissions`(45), `roles`(7), `service_categories`(7), `service_subcategories`(3), `services`(8), `users`(2), `zones`(3).

This matches `PROJECT_CURRENT_STATE.md` §2's own stated figure exactly: *"Production data volume ... 0 bookings, 0 commissions, 0 coupons, 2 users, 4 franchises, 3 zones."*

---

## 2. Migration state

- **226 migration files** exist in the local `main`-branch repo (`database/migrations`), matching `226` total rows the server's `migrate:status` accounts for (119 Ran + 107 Pending).
- **119 have run**, in batches 1–32. The last-applied batch (32) is `2026_08_11_053000_seed_commissions_permission`.
- **107 are pending** — not the ~180 originally estimated. All 107 pending filenames were checked one-by-one against the local repo's `database/migrations` directory: **all 107 are present in `main`**, nothing pending on the server is missing from the repo, and nothing in the repo is a mismatch with what the server expects.
- **Cross-reference against `git log`:** every pending migration's owning commit falls in a continuous, dated window from **2026-08-11 to 2026-08-18** (`git log --oneline --all -- database/migrations`), matching real commits already described in `PROJECT_CURRENT_STATE.md`'s addenda and `CURRENT_MASTER_CHECKPOINT.md` (Plan Engine, Worker/RBAC foundation Aug 11; KYC/Compensation/Printing/Ops Aug 14; CMS/parity audit Aug 14–15; Module Activation + Parcel + Taxi + Property Rental Aug 16; Marketplace/Ecommerce + Rental-generalization + Hotel Aug 17; FieldWorker payout Aug 18). This is a genuine, single continuous burst of planned development, not an inconsistent or suspicious commit history.
- **One filename-date quirk worth naming plainly, not glossing over:** several pending migrations carry filename timestamps *later than their actual commit date* — e.g. `2026_08_21_...rename_property_rental_module_to_rental` and the entire `2026_08_21_*`/`2026_08_22_*` Rental/Hotel migration set were all committed for real on **2026-08-17** (`git log` shows `ec789af`/`03c1293`, dated 2026-08-17). This is a Laravel migration-filename sequencing convention (later timestamp = runs after earlier ones, chosen to preserve mission "Day N" ordering), not a sign of tampering or a wrong system clock — but it does mean **migration filename dates cannot be read as literal calendar/commit dates** for this repo; the `git log` commit dates are the real timeline.
- **The prompt's premise that the `bookings` table "has real batch-32 data in it" does not hold up against this session's own read:** `bookings` has **0 rows**, full stop (§1 above). No document in this repo corroborates a "batch-32 bookings data" claim either — a repo-wide search for that phrasing found nothing. Batch 32 is real (it's the last-applied migration batch number, confirmed directly), but it is not tied to any real booking data; the confusion appears to conflate "last migration batch that ran" with "data present in the bookings table," which are unrelated facts. Stated plainly so it isn't carried forward into the next session's assumptions.

---

## 3. Risk classification of every pending migration

Every one of the 107 pending migration files was opened and its actual `up()`/`down()` body read, not inferred from the filename. Result:

- **SAFE-ADDITIVE: 89** — new tables, new nullable/defaulted columns, new indexes, or fresh permission/lookup-row seeds.
- **RISKY-STRUCTURAL: 18** — enum widenings via `->change()`, one real column rename (`otps.code` → `otps.code_hash`), UPDATE-based backfills against existing rows, and the three `modules`-table renames.
- **UNCLEAR: 0** — every mechanism was confidently determinable from source; nothing required guessing at runtime behavior.

Of the 18 RISKY-STRUCTURAL migrations, only **3** add a new FK/unique constraint on an *existing, already-populated* column — the specific shape that can literally error out at migrate-time if a violating row exists: `2026_08_12_001000_add_unique_constraint_to_commissions_booking_id`, `2026_08_12_002000_add_foreign_key_to_bookings_coupon_id`, and `2026_08_15_001000_add_unique_booking_id_to_reviews_table`. Given this database's actual row counts (§1: `commissions`=0, `bookings`=0, `reviews`=0), none of these three would actually fail if run today — there's no existing data to violate the new constraint. The remaining 15 RISKY-STRUCTURAL migrations are enum widenings, the one column rename, or data-mutating UPDATEs against existing rows (structurally a different, real category of change even where nothing would technically fail against today's near-empty tables).

### §3a. The three `modules`-table rename migrations, examined directly (named in the brief)

None of these three touch the `bookings` table. All three are single-row `UPDATE`s against the small `modules` registry table (9 rows total today), changing one row's `code`/`name` columns:

```php
// 2026_08_20_001000_rename_car_rental_module_to_property_rental
DB::table('modules')->where('code', 'car_rental')
    ->update(['code' => 'property_rental', 'name' => 'Property Rental', 'updated_at' => now()]);

// 2026_08_21_001000_rename_property_rental_module_to_rental
DB::table('modules')->where('code', 'property_rental')
    ->update(['code' => 'rental', 'name' => 'Rental', 'updated_at' => now()]);

// 2026_08_22_001000_rename_bookings_module_to_hotel
DB::table('modules')->where('code', 'bookings')
    ->update(['code' => 'hotel', 'name' => 'Hotel / Stay', 'updated_at' => now()]);
```

Each migration's own docblock states the reasoning: `module_activations.module_id` is a proper integer foreign key, never the `code` string, so renaming a row's `code` in place cannot orphan any relationship. Classified **RISKY-STRUCTURAL** in the table below anyway (they mutate an existing row's identity column, not just add new structure) — but the actual blast radius is one row each in a 9-row config table, not the Service-vertical `bookings` table, which these do not reference at all.

### §3b. Full classification table

See **`docs/DATABASE_AUDIT_2026-08-21_MIGRATIONS.md`** for the complete 107-row table (migration name / classification / explanation), produced by opening and reading every pending migration file individually.

### §3c. A finding beyond simple classification: the live app already depends on some of these

This is the audit's most concrete finding, and it changes how "risk" should be read for this specific pending set. Two currently-deployed, currently-reachable code paths already assume migrations from this pending set have run:

1. **`modules.is_implemented`** (added by pending migration `2026_08_16_900000_add_is_implemented_to_modules_table`) does not exist on the live `modules` table today — confirmed directly via `DESCRIBE modules` (columns present: `id, code, name, sort_order, is_active, created_at, updated_at` — no `is_implemented`). `App\Services\ModuleActivationService::isActive()` reads `$module->is_implemented` and treats a falsy value (which includes "column doesn't exist, Eloquent returns null") as "not implemented," unconditionally returning `false`. `App\Actions\CreateBookingAction` calls this for the Service vertical on every booking-creation attempt and throws `ModuleNotActiveException` when it returns `false`. **Net effect: every real booking-creation attempt today — both the admin/call-center Livewire flow and the newer customer-facing `POST /api/bookings` — is being rejected in production**, not because Service is deliberately disabled, but because the column its own activation check depends on hasn't been created yet. This is silent today only because real booking-creation traffic is apparently zero (§1); it is a live bug, not a hypothetical one, the moment anyone tries it.
2. **`payment_webhook_logs`** (created by pending migration `2026_08_14_030000_create_payment_webhook_logs_table`) does not exist on the live database. `App\Http\Controllers\API\PaymentController::webhook()` calls `PaymentWebhookLog::create([...])` unconditionally, with no `try/catch`, both on the invalid-signature path and after a real `payment.captured`/`payment.failed` event has already been handled by `RazorpayWebhookHandler`. A real Razorpay webhook call today would throw an uncaught `QueryException` ("table doesn't exist") at that line — after the payment side-effect has already run, not before — meaning a 500 response would go back to Razorpay (triggering a retry) even though the underlying payment may have already been processed. Currently unverified against a live payment (0 payments recorded, §1), so this has not yet been observed in practice, but it is directly demonstrable by reading the code against the confirmed-missing table, not a guess.

These two were the only ones specifically traced end-to-end this session (both read directly against the live schema, both traced through their exact calling code path); a full audit of every other pending-table dependency in currently-deployed code (`badges`, `flash_sales`, `performance_campaigns`, `qr_challenges`, the KYC tables, the four new-vertical order tables, etc.) was not performed — this is a real, not-yet-fully-scoped risk surface, named here rather than assumed absent.

---

## 4. Data reality assessment (no PII printed — patterns/counts/domains only)

| Table | Count | Range | Pattern |
|---|---|---|---|
| `users` | 2 | 2026-08-06 07:41–07:48 (7 min apart) | Both `role=super_admin`, both `@1callfix.com` domain. Internal team/admin seed accounts, not customers. |
| `franchises` | 4 | 2026-07-29 to 2026-08-10 | Names: "Nellore", "ZF Kurnool Renamed", "ZF Pending", "Guntur 1" — the "Renamed"/"Pending" naming is characteristic of an admin manually testing the create/rename/status-toggle UI, not real franchise onboarding copy. |
| `zones` | 3 | — | "Nellore Central", "Nellore 1", "Nellore2" — same manual-test-entry pattern (inconsistent spacing/numbering). |
| `service_categories` | 7 | — | Mix of real-looking ("AC Repair", "Fridge Repair", "Blood Test") and clearly manual test entries ("TV ", trailing space) alongside a duplicate-looking "Appliance | AC Repair". |
| `services` | 8 | — | Real-looking entries ("AC Service (1 Ton)", "Fridge Gas Refill") alongside unambiguous keyboard-mash test rows: `adsfaf`, `butter`, `mob`, `sdffda`, `alarm`. |
| `banners` | 2 | 2026-08-10 | Titles "Monsoon Offer", "PPt" — one real-looking, one a literal test title. |
| `booking_sequences` | 5 | 2026-07-29 to 2026-08-11, `last_number` up to 38 | Real evidence that real booking-creation attempts *did* happen (the atomic per-day counter was incremented, up to 38 on 2026-08-11) even though `bookings` itself is now empty — consistent with dev/QA bookings having been created and later deleted/cleaned (the counter is deliberately monotonic and never rolls back on row deletion, so this is expected, not a discrepancy). |
| `notification_logs` | 42 | 2026-08-11 only, `channel=mail` only | Single-day burst, single channel — consistent with one dev/test session exercising the notification pipeline, not real customer traffic. |
| `failed_jobs` | 1 | 2026-07-30 | A single stale entry from early dispatch-engine testing. |
| `settings` | 3 | — | Only `cancellation.*` keys populated; every other documented Setting namespace (KYC, Compensation, Auth/OTP, Operations, Referral, Subscription — all real, all read by live code per `PROJECT_CURRENT_STATE.md` §12/Phase 11) is still at its code-level default, never admin-configured. |

**Conclusion: this is genuine early-setup/developer-testing data, not synthetic QA-seeder output and not real customer data.** Two independent signals rule out `QaSeeder`: (1) `QaSeeder::scaleCounts()` produces 8–50 customers, 6–40 workers, 20–200 bookings, 6–30 subscriptions at its smallest ("small") scale — the live counts (0 bookings, 0 subscriptions, 2 users both `super_admin`) are far below even that floor, and `QaSeeder`'s own manifest-tracked `qa:clean` removes exactly what it created, so a genuine QA-seeded-then-cleaned database should show zero residue, not partial rows; (2) `QaSeeder` never inserts a franchise named "ZF Pending" or a service named "sdffda" — those are hand-typed. The `booking_sequences`/`notification_logs` residue is best read as manual admin-panel click-through testing during the Aug 11 RBAC/notification build session, later partially cleaned (bookings/users mostly reset) but not fully (the sequence counter and log rows were never touched by whatever cleanup ran). No email domain, phone pattern, or name resembling a real customer was found or printed — the two `users` rows are both internal `@1callfix.com` accounts.

---

## 5. Does this match documented project history, or is it undocumented state?

**Matches, on every point checked except one — and that one point (the live schema/code gap in §3c) is new, not previously logged anywhere in the repo.**

- The **exact row counts** in §1 (0 bookings/commissions/coupons, 2 users, 4 franchises, 3 zones) are already stated verbatim in `PROJECT_CURRENT_STATE.md` §2 ("Production data volume as of this session... a pre-launch/early-setup state, not a system carrying real customer transaction volume yet"). Nothing here contradicts that document; this session independently re-confirmed it.
- The **107 pending migrations, and the three `modules`-table renames by name**, are already anticipated: `docs/ROLLBACK_PLAN.md` §3 explicitly names "**A migration that seeds/renames data** (e.g. the `rename_*_module_to_*` pattern used repeatedly in this codebase's own migration history)" as a known category requiring extra care beyond a bare `migrate:rollback`. `docs/DEPLOYMENT_RUNBOOK.md` requires a fresh backup "before EVERY deploy that includes a migration, no exceptions." `KNOWN_RISKS_AND_DECISIONS.md`'s 2026-08-20 entry states "All 64 migrations from at least the last three build sessions confirmed to have real, non-empty `down()` implementations" — i.e., a prior session already specifically audited these migrations' reversibility in anticipation of running them for real. This is a **planned, documented, deliberately-paused rollout**, not an accident.
- **What is genuinely new, found only by this session's direct schema read against the live database:** the §3c finding that currently-deployed code (`ModuleActivationService`, `PaymentController::webhook()`) already assumes columns/tables from the pending set exist. Nothing in `KNOWN_RISKS_AND_DECISIONS.md`'s 59 items, `PROJECT_CURRENT_STATE.md`, or `CURRENT_MASTER_CHECKPOINT.md` names this specific gap — the closest is `CURRENT_MASTER_CHECKPOINT.md`'s note that "no vertical was activated... that remains the single open business decision" and `KNOWN_RISKS_AND_DECISIONS.md`'s item 59 (unrelated, about `public/build/` and Vite), neither of which anticipated that the *Service* vertical itself — not a disabled future one — would be blocked by the same mechanism. This should be treated as a real, previously-undocumented finding of this audit, not something already covered.

---

*Audit performed 2026-08-21, entirely read-only, against `srv1422426.hstgr.cloud` / `api.1callfix.com` production database and the local `1callfix-demo` repo. No migration, seed, or data change was made at any point in this session.*
