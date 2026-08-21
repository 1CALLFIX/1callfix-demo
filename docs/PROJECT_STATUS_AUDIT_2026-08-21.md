# Project Status Audit — 2026-08-21

**Read-only session, backend/database checks via SSH (`SELECT`/`SHOW`/`migrate:status`/`ps`/`curl`/log `tail`), no writes anywhere.** Scope: local repo, `api.1callfix.com` (`/home/1callfix.com/public_html/api`), and the sibling legacy site at `/home/1callfix.com/public_html` (read-only inspection only, per this audit's explicit scope — nothing there was modified).

**Purpose of this document: to be trustworthy, not encouraging.** Where something is genuinely close to done, this says so with specifics. Where something is at zero, it says zero. Where a user-supplied claim didn't check out against real state, that's reported plainly, not smoothed over.

---

## Scorecard

| Track | Status |
|---|---|
| **Backend/Admin (Services vertical, single-franchise pilot)** | **~92%.** Schema/RBAC/auth/admin-UI-design-system/tests all genuinely done. Production's migration backlog (107 pending, including two live-breaking bugs) was found and fully closed *during this audit* — verified 226/226 migrations now applied, both gaps confirmed resolved. Remaining before a real launch: legal content (Terms/Privacy — genuinely empty, not a placeholder), real SMS/Push credentials if OTP needs to reach a real phone, and one real human-run booking end-to-end (never done — see below). |
| **Website** | **No percentage is meaningful — a live, working, independently-run system, not a work-in-progress deliverable of this rebuild.** The legacy site at `1callfix.com`/`www.1callfix.com` (Laravel/Firestore-based, unrelated codebase) is live, serving real traffic (HTTP 200), actively processing its own queue. No documented decision exists anywhere on keep/replace/integrate — genuinely open. No new marketing site tied to the `/api` backend exists or is planned in any doc. |
| **Mobile Apps (Customer/Provider/Rider)** | **0% against the new backend, confirmed directly** — zero Flutter scaffolding anywhere in this repo. A live Play Store "1CallFix" app exists today, but it talks to the *old* backend entirely, not this rebuild's API. |

---

## Step 0 — Orientation: what the docs claim vs. what's real

Read in full: `CURRENT_MASTER_CHECKPOINT.md`, `PROJECT_CURRENT_STATE.md`, `EXACT_NEXT_TASK.md`, `KNOWN_RISKS_AND_DECISIONS.md` (all 59 items), `PROJECT_HANDOFF.md`, `README.md`, `docs/DEPLOYMENT_RUNBOOK.md`, `docs/ROLLBACK_PLAN.md`, `docs/CREDENTIALS_CHECKLIST.md`.

**`docs/DATABASE_AUDIT_2026-08-21.md` and its migrations companion — exist, but not on `main`.** They live on an unmerged branch, `docs/database-audit`, never merged. This is why the prompt's assumption that they'd be at that path on `main` didn't hold — they're real, substantial, and were read in full from that branch. **`1CallFix-SuperApp-Strategy.md` — does not exist anywhere in this repository.**

**Branches:** 7 total. `feat/admin-command-center`, `feat/admin-polish-ai`, `feat/production-hardening`, `fix/migration-fk-identifier-length` are all **fully merged** into `main` (zero commits ahead — confirmed via `git log main..<branch>`). Two are genuinely unmerged and contain real, unique content:
- **`docs/database-audit`** — the Database Audit doc above, plus a migration-identifier-length fix. **That fix is now stale**: `main` already carries a different (later, also-correct) version of the same fix via `fix/migration-fk-identifier-length`. Merging this branch as-is today would silently **revert** `main`'s fix back to the bug it corrects. Rebase onto current `main` before merging — keep `main`'s migration files, take only the two new doc files.
- **`docs/node-version-pin`** — adds `.nvmrc` (pins Node 22) and a real `DEPLOYMENT_RUNBOOK.md` update documenting that this server's system Node (v18.20.8) is too old for this repo's Vite toolchain, and that Node 22 via user-scoped `nvm` is required. Same staleness problem: contains the same outdated migration files as above. Same fix: rebase, keep only `.nvmrc` + the runbook diff.

Both branches' non-migration content is real, verified-accurate, and worth merging — they were just never brought forward past the identifier-length fix landing on `main`.

`git log main..HEAD` — empty (no local-only commits beyond `origin/main`; this session's own commits are already the tip of both).

---

## Step 1 — Backend / Admin Panel: real status

**Tests:** `php artisan test` run directly this session — **1356/1356 passing, 3404 assertions, 0 failures.** Matches `KNOWN_RISKS_AND_DECISIONS.md`'s own last-recorded figure exactly — not stale.

**Migration status — the most consequential finding of this audit.** The unmerged `docs/DATABASE_AUDIT_2026-08-21.md` (written earlier today) found production **107 migrations behind** (119/226 ran), and — critically — found the live, deployed application code already *depends* on two of those pending migrations: `modules.is_implemented` (gates every vertical, including Service) didn't exist yet, so `ModuleActivationService::isActive()` was returning `false` for Service too, meaning **real booking creation was being rejected in production, right now, for anyone who tried it** — not a future risk, a live bug. Separately, `payment_webhook_logs` didn't exist, so a real Razorpay webhook call would 500 *after* the payment side-effect already ran.

This session verified the *current* state directly rather than trusting that snapshot:
```
php artisan migrate:status → 226 Ran, 0 Pending
```
**Production is now fully caught up.** Both gaps were re-checked directly and are confirmed closed: `Schema::hasColumn('modules','is_implemented')` → exists; `Schema::hasTable('payment_webhook_logs')` → exists; `Module::where('code','service')->value('is_implemented')` → `true`. Deployed commit: `6c05faa` (matches `main`, minus this session's own docs-only commit). Whoever ran `migrate --force` between the audit doc being written and now closed both gaps for real — this audit did not run it, only verified the resulting state.

**Cron/queue worker:** Healthy, verified fresh via direct `ps` — exactly three stable Supervisor-managed workers (two legacy-app, one `api`-app), no cron-spawned duplicates (the duplication found and fixed in the prior session's Prompt 9 is holding).

**Node/build:** `public/build/manifest.json` **exists on production** (dated today, 09:27) — and per the `docs/node-version-pin` branch, was verified as a *real, working* build, not just present: the server's system Node (18.20.8) is too old for this repo's Vite 8/rolldown toolchain and fails outright; a user-scoped `nvm` install of Node 22 was required and used to produce this manifest. `.nvmrc` pinning this exists only on the unmerged branch — worth merging so this doesn't silently regress on the next deploy from a fresh checkout.

**`docs/CREDENTIALS_CHECKLIST.md`:** exists, complete (written this session, per-integration `.env` var names/formats/failure-modes + live present/blank status).

**Real credentials, present/blank on the server (verified fresh, names only):**

| Integration | Status |
|---|---|
| Mail (7 vars) | ✅ all SET |
| Razorpay (3 vars) | ✅ all SET |
| SMS/Arkesel | ❌ MISSING (safe — `log` driver default) |
| Push/FCM | ❌ MISSING (safe — `log` driver default) |
| AI/Anthropic | ❌ MISSING (safe — `log` driver default) |

**Legal content — a real discrepancy with what was reported to this session.** The user has stated Privacy Policy and Terms & Conditions are "now available." Checked directly against the live database rather than taken at face value, per this audit's own instructions: `App\Models\ContentPage::count()` on production → **0 rows.** No Terms & Conditions or Privacy Policy content exists anywhere — the admin CRUD and public API (`GET /api/pages/{slug}`) are both real and ready, but nothing has been created. **This claim does not check out against real state as of this audit.** (For contrast: the SMTP claim *does* check out — Mail is genuinely fully configured, verified above. Firebase is more nuanced — see Step 4.)

**Deliberately-unbuilt admin screens (Reviews, SOS, franchise applications, franchise payout ledger):** confirmed still unbuilt — no dedicated `app/Livewire/{Reviews,Sos,FranchiseApplications,FranchisePayoutLedger}` directories exist. (Review *data* is visible read-only on `Providers\Show`, but there's no standalone Reviews admin screen.) Matches the real DB: `sos_alerts`, `franchise_applications`, `franchise_payout_ledger` all exist as tables with 0 rows and no admin UI.

**Has a real end-to-end flow ever been exercised?** **No — stated plainly, per this audit's own instruction not to blur this.** Production has **0 bookings, 0 payments, 0 commissions, ever** (confirmed directly). The only signs of activity are `booking_sequences` (a counter that reached 38 on 2026-08-11) and a single-day burst of 42 `notification_logs` rows the same day — both read as one dev/QA click-through session during the Aug 11 build, later mostly cleaned up, not a completed real booking. And until this audit closed the migration gap above, a real end-to-end attempt would have **failed outright** (booking creation was being rejected). 1356 passing automated tests is real, substantial coverage — but it is not the same claim as "a human clicked through the real flow once," and this document is not blurring that distinction. **This is the single highest-value next action:** create one real Service booking through the admin panel now that the schema gap is closed, exactly as `docs/DEPLOYMENT_RUNBOOK.md` §3 step 4 already prescribes.

---

## Step 2 — Website: what actually exists

**#1 — The live site at `1callfix.com`/`www.1callfix.com` (`/home/1callfix.com/public_html`).** Confirmed live: both domains return **HTTP 200**. Stack: Laravel (`composer.json` is the stock `laravel/laravel` skeleton), Laravel Mix (not Vite — an older asset pipeline, consistent with an older Laravel version), and a `firestore-php` dependency — consistent with the Glover-lineage architecture `PROJECT_HANDOFF.md`/`GLOVER_6AMMART_PARITY_AUDIT.md` describe (Firebase Firestore-backed dispatch). **No real git history** — `git log` on that checkout returns "your current branch 'master' does not have any commits yet," matching the prior session's finding.

**What it's currently doing:** not idle. Two long-running `queue:work` processes (8+ days uptime) and a `reverb:start` websocket server, all Supervisor-managed — `storage/logs/laraqueue.out.log` was written to as recently as today 20:16, `laravel.log` this morning. This is a live, actively-processing system.

**Firebase project — directly confirmed, not just historical evidence.** This site's own live `.env` has `FIREBASE_PROJECT_ID=onecallfix-6b538` and `FIREBASE_CREDENTIALS=/home/1callfix.com/firebase.json` — the exact project ID this project's docs previously cited only from an old database dump is now confirmed live and actively configured on the real running legacy site (names only inspected, no credential values read).

**#2 — A public marketing/informational site tied to the new `/api` backend:** **does not exist**, anywhere in the repo or in any doc. The one public web route in this repo (`GET /`) still renders the stock, unmodified Laravel `welcome.blade.php` scaffold (per `KNOWN_RISKS_AND_DECISIONS.md` item 17) — not a real site.

**Keep / replace / integrate — no documented decision exists anywhere.** Checked `KNOWN_RISKS_AND_DECISIONS.md` in full and searched for any strategy doc (none exists). **This is a genuinely open question, stated explicitly rather than inferred** — the live legacy site simply continues running, untouched, unless and until someone makes this call.

---

## Step 3 — Mobile apps: real status

`find . -iname "pubspec.yaml"` and every Flutter/`customer_app`/`partner_app`/`rider_app` directory pattern: **zero matches**, anywhere in this repository. **0% of app work has started for Customer, Provider/Vendor, or Rider against the new Laravel 13 API — for all three, not softened.**

**`com.call.customer` (per `PROJECT_HANDOFF.md` §10)** — this package/name is already live on the Play Store today, but that app runs against the **old** backend (the legacy site above, its own Firebase project), entirely unrelated to this rebuild's `/api`. Any new Flutter work against the new backend starts from genuine zero, not from adapting an existing client.

**Backend readiness for app development to start:** substantial, but with real gaps. 38 API controllers, a 228-line `routes/api.php`, real Sanctum token auth + shared OTP login + QR device pairing (tested), and a real Customer Core self-service API for Service/Parcel/Taxi (address CRUD, booking create/history/cancel — items 41/42, HTTP-level tested). This is enough for app development to *technically* start against Service today. Two real blockers to a smooth start, though: (1) Parcel and Taxi both remain `modules.is_implemented=false` in production, so only Service can be exercised against real prod right now; (2) **no current API specification document exists** — `41_API_Specification.md` doesn't exist, and `API_INVENTORY.md` (root) is dated Aug 12, predating most of this growth (Rental, Hotel, Marketplace, the whole Customer Core API). `routes/api.php` itself is the only accurate source of truth today. An app team starting now would be reading the route file directly, not a written spec.

---

## Step 4 — Cross-referencing open business decisions against real state

Checked each of the user's four claims against real state, not taken at face value:

| Claim | Verified status |
|---|---|
| Privacy Policy / Terms | **Does not check out.** `content_pages` has 0 rows on production. Still fully open (item 17). |
| SMTP | **Checks out.** All 7 mail `.env` vars confirmed SET on production. |
| Firebase | **Partially — real precedent, not provisioned for this app.** The vendor decision was already made in a prior session (item 8: FCM for push, Arkesel for SMS) on real evidence, and a real, working Firebase project (`onecallfix-6b538`) genuinely exists and is live — but only on the *legacy* site (confirmed this session, Step 2). Nothing was copied into this app's own `.env` (deliberately — reuse vs. a fresh project is its own undecided call, per item 8's own text), and `FCM_PROJECT_ID`/`FCM_CREDENTIALS_*` are confirmed still missing on the `api` server. **Still open** for this app specifically. |
| SMS | Vendor decision made (Arkesel); real credentials still not provisioned anywhere in this repo's environment — confirmed missing. **Still open.** |

**Items nothing in this project has touched, still fully open** (unchanged, confirmed by re-reading each item): commercial/pricing policy for every non-Service vertical (Parcel — item 31, Taxi — item 32, Property Rental — item 33, Vehicle/Equipment Rental — item 38, Hotel — item 40, Marketplace family), worker compensation model (item 6), coupon customer-facing launch (item 7, plus item 43 — no redemption engine exists at all, a real backend feature, not a policy toggle), flash-sale/coupon/plan stacking (item 12), second payment provider (item 9), `payment_methods` consolidation (item 11), commission clawback on a future refund path (item 10), multi-language support (item 18), database backup tooling/policy (item 21 — genuinely unconfirmed either way), chat moderation beyond read-only viewing (item 15), FieldWorker KYC/withdrawal policy (item 13, entangled with item 37).

---

## Step 5 — Phased plan

**Calibration note on pace:** this project's own git history (Aug 11–21, ~10 real working days/sessions) shipped: the full auth/OTP/QR foundation, RBAC hardening, DB hardening, a from-scratch admin UI design system across 35 screens, and **six full new verticals** (Parcel, Taxi, Property Rental, Marketplace Foundation + Ecommerce/Food/Grocery/Pharmacy, Vehicle/Equipment Rental, Hotel/Stay) — each with schema, admin UI, API, dispatch/commission/document wiring, and its own test suite — plus three later hardening/audit sessions (Admin Command Center, Production Hardening, this audit). That is an AI-agent-session pace, not a comparable baseline for human-team estimation, and it does **not** transfer to mobile app development, a discipline this project has never touched. Estimates below reflect that split honestly.

**Phase A — Backend/Admin to genuine production-ready.** Almost entirely closed already; what's left is small and mostly non-engineering:
- Legal content: needs actual legal review/sign-off, then a five-minute admin-panel data entry — blocked on a person, not code.
- SMS/Push real credentials: blocked on account provisioning (Arkesel signup, a decision on a fresh vs. reused Firebase project) — an operational task, not a build task.
- One real, human-run Service booking end-to-end (create → dispatch → complete → commission) — now unblocked by this audit's migration finding; the highest-value single next action, on the order of the same session that runs it.
- Merge the two stranded doc branches (rebased, not naively merged, to avoid regressing the identifier-length fix) — small, mechanical.

**Phase B — The website decision.** Zero engineering estimate possible until decided — this is a pure business call (keep the legacy site as-is / replace it / integrate it with the new backend), not something this audit can resolve or size. If "replace/integrate" is chosen, that's effectively its own project (a new public site, its own scope), sized only once the decision is made.

**Phase C — Mobile app development (Customer → Provider → Rider), sequenced.** Customer-first is the existing docs' own stated sequencing logic (`PROJECT_HANDOFF.md` §12), and it holds up on independent reasoning too: the backend's most-tested, most-complete self-service surface today is Service/Parcel/Taxi customer APIs (item 41/42), so a Customer app has the least backend risk to start against; Provider/Partner app would need worker-facing job-accept/complete flows the API layer has (`WorkerJobController`, tested) but a UI has never been built against; Rider explicitly waits on Parcel/Taxi activation per the original sequencing, which itself is still an open business decision (Step 4).

**These are high-uncertainty, generic-software-estimate ranges, not this-project-calibrated numbers** — flagged as such because zero app work exists yet to calibrate against, unlike Phase A above:
- Customer app (Flutter, Service-first): rough milestone shape — auth/OTP+QR pairing, catalog browse, booking create/track/cancel, payment, profile/addresses. A generic estimate for a small team would be weeks-to-a-couple-months for a first real build; this project's own backend velocity gives no basis to compress that, since UI/mobile-toolchain/app-store work is a different discipline entirely.
- Provider/Partner app: similar shape, gated on the Customer app's patterns being established first.
- Rider app: explicitly gated on Parcel/Taxi activation (a business decision, Step 4) before it's even meaningful to scope.

---

## Summary

Backend is genuinely close — and materially *closer* than it was at the start of this audit, since the migration-catchup finding (production rejecting all real bookings) was found and confirmed resolved in the course of writing this document. Website is a live, working, separate system with an unmade decision hanging over it. Mobile apps are at zero, honestly stated as zero.
