# 1CallFix Services — Master Project Handoff

> **HISTORICAL / SUPERSEDED as of 2026-08-12.** This document predates RBAC, the Plan Engine, the Worker/Rider architecture, and most of the current Admin Panel screens, and incorrectly describes several since-shipped features as not yet built. **[`PROJECT_CURRENT_STATE.md`](PROJECT_CURRENT_STATE.md) is now the authoritative current-state document** — read that first. This file is kept for its still-accurate infrastructure/deployment notes (server paths, backup schedule, known one-time infra bugs, §9 below).

**Read this first, before touching any code.** This is the single source of truth for the project's real state. If any tool, session, or person contradicts this document based on local files, template folders, or assumptions — this document wins. Verify against the live server/repo, not against guesses.

---

## 1. What This Project Actually Is

A from-scratch, franchise-ready home-services marketplace (UrbanClap/Urban Company-style), built for **1CallFix Solutions Pvt Ltd** (operating since 2012, incorporated 2018, Nellore, Andhra Pradesh, India). Founder: Mohammed Shabbeer Shaik.

Glover (an existing multi-vendor marketplace codebase 1CallFix already owns/uses) and 6amMart (a commercial template) are **reference material only** — studied for architectural ideas (dispatch algorithms, schema patterns, feature lists), never copied wholesale. This project is a clean, independent codebase.

**Long-term vision:** a full super-app with 8 toggleable verticals. **Only 1 of 8 is built.** See Section 4.

---

## 2. Where The Real Project Lives

- **Live server:** `https://api.1callfix.com`
- **Server path:** `/home/1callfix.com/public_html/api/`
- **Deploy user:** `callf1207` (site owner; `root` also has access but causes file-permission issues if used for git/deploys — always prefer `callf1207`)
- **GitHub:** `1CALLFIX/1callfix-demo` (private repo) — this is the real, authoritative codebase
- **Hosting:** Hostinger VPS, CyberPanel + OpenLiteSpeed webserver, `srv1422426.hstgr.cloud`

**If any tool/session doesn't have access to this repo and server, it does not have the real project context and should not generate code as if it does.**

---

## 3. Tech Stack (confirmed installed and in use)

- **Laravel 13.23.0**, PHP 8.3.30
- **MySQL** (database: `1cal_api`)
- **Livewire 4.3.5** (admin panel — no separate frontend build step, Tailwind via CDN)
- **Sanctum 4.3.3** (API token auth, for mobile apps)
- **Supervisor** (manages the persistent queue worker — `onecallfix-worker`)
- **Razorpay** (payments, test mode currently)
- Redis is installed and running on the server but **not currently used** by this app (queue/cache/session all use `database`/`file` drivers)

---

## 4. The 8 Modules (super-app verticals)

Tracked in the `franchise_modules` table (boolean flags per franchise):

| Module | Status |
|---|---|
| **Service** (home services: AC, electrical, plumbing, carpentry, appliance repair) | ✅ **Built and live** — the only functional vertical |
| Food | ❌ Not built — flag exists, no functionality |
| Parcel | ❌ Not built |
| Taxi | ❌ Not built |
| Grocery | ❌ Not built |
| Pharmacy | ❌ Not built |
| Commerce | ❌ Not built |
| Bookings (hotel/property) | ❌ Not built |

**Deliberate sequencing decision:** Service must be proven profitable with real revenue before any other vertical gets built. This avoids diluting a working provider pool across untested verticals. When the time comes, Parcel is next (cheapest — reuses the dispatch engine as-is), then Food (unlocks a shared Catalog+Cart engine that also covers Grocery/Pharmacy/Commerce cheaply), then Taxi (needs real-time trip/fare logic), then Bookings (most structurally different — date-range availability).

**One unified codebase, not separate apps per vertical.** When other verticals get built, they get their own order tables (`food_orders`, `parcel_orders`, etc.) rather than one shared table — this avoids the "god object" problem found in Glover's own `Order` model, which tries to represent food/taxi/parcel/service all at once.

---

## 5. Schema — What Exists

**46+ tables**, covering all 3 original build phases up front (Phase 1 foundation, Phase 2 growth features, Phase 3 franchise-scale) — built this way deliberately so nothing needs retrofitting later.

Core tables: `franchises`, `zones`, `users`, `addresses`, `service_categories`, `services`, `service_options`, `franchise_service_pricing`, `providers`, `provider_documents`, `provider_subscriptions`, `subscription_plans`, `bookings`, `booking_options`, `booking_status_history`, `dispatch_attempts`, `payments`, `wallets`, `wallet_transactions`, `commissions`, `reviews`, `coupons`, `protection_plans`, `payouts`, `banners`, `referrals`, `loyalty_points`, `franchise_modules`, `booking_sequences`, `booking_extra_items`, and more.

**Every operational table carries `franchise_id`, and most carry `zone_id`** — this is the core design decision that makes multi-franchise scaling a config change, not a rebuild.

---

## 6. Key Architectural Decisions (don't relitigate these without reason)

- **Order code format:** `{FRANCHISE_CODE}-{DDMM}-{8-digit sequence}`, e.g. `NLR-2907-00000001`. Sequence resets daily, per franchise, using an atomic MySQL counter (`booking_sequences` table) — race-condition-safe under concurrent bookings.
- **Franchise/Zone codes auto-generate** from name (first 3 letters, e.g. "Nellore" → "NLR"), with numeric fallback on collision (`NLR2`). Can be manually overridden by typing a code explicitly when creating.
- **Dispatch model:** Admin-controlled auto-assignment only. No customer-facing provider browsing/directory. A "book this provider again" shortcut exists for repeat customers, but that's the only exception. This mirrors Urban Company's trust-building model, not a Justdial-style directory.
- **Dispatch engine (`ServiceMatchingJob`):** Self-requeuing queued job, adapted from Glover's real `RegularOrderMatchingJob` pattern but running on our own MySQL/Haversine distance calc instead of Firebase Firestore. Offers go to up to 5 nearest eligible providers at once, 25-second window, re-broadcasts up to 6 rounds before falling to the admin's manual queue.
- **Hold/red-flag layer:** `bookings.hold_category` splits into `customer_side` (routine — spares, approval, payment decision) vs. `provider_side` (red flag — unresponsive, payment not reconciled). Category is auto-derived from the specific reason, never passed separately, so it can't be mismatched.
- **Extra-work billing:** A provider mid-job can propose extra work (`booking_extra_items`), which auto-holds the booking (`awaiting_customer_approval`), and the customer approves/rejects per item. Final price = base quote + only approved extras.
- **Commission split:** Calculated at job **completion** (via verified OTP), not at payment capture — these are treated as separate events. `CommissionService` is idempotent (safe to call more than once without double-crediting).
- **Wallet service:** Transaction-safe (row-locked), refuses to let a wallet go negative.

---

## 7. Build Status By Milestone

| Milestone | Status |
|---|---|
| M0 — Schema, infra, subdomain live | ✅ Done |
| Order codes, wallet, franchise/zone auto-coding | ✅ Done, tested |
| M3 — Dispatch engine | ✅ Done, tested end-to-end with real data |
| Hold/red-flag layer | ✅ Done, tested |
| M4 — Payments (Razorpay), commission split | ✅ Done, tested end-to-end |
| Extra-work billing | ✅ Done, tested end-to-end |
| `franchise_modules`, banner ad revenue fields | ✅ Done |
| **M5 — Admin Panel (Livewire)** | 🔶 **In progress** — see Section 8 |
| M6 — Customer app (Flutter, "1CallFix") | ❌ Not started |
| M7 — Partner app (Flutter, "1CallFix Partner") | ❌ Not started |
| Rider app ("1CallFix Rider") | ❌ Not started — waits for Parcel/Food verticals |

---

## 8. Admin Panel (M5) — Current State

**Real, working screens, live at `api.1callfix.com/admin/...`:**
- Login (session auth, `role = super_admin` required)
- Dashboard — live KPIs, operational funnel (searching/assigned/in-progress/completed/cancelled/disputed), This Week/Month/Year toggle
- Bookings — list with status filter + code search, detail page (full context: customer, provider, price, commission, extra-work items, dispatch attempts, status history), manual reassign, cancel
- Providers — list with KYC status filter, detail/review page, approve/reject actions
- Franchises — list (with zone/provider/booking counts, module badges), create/edit form (commission settings, status, all 8 module toggles)

- Zones — list (franchise, provider/booking counts), create/edit with a hand-rolled click-to-place-point boundary map (`public/js/zone-map.js`; not `google.maps.drawing.DrawingManager`, which Google removed as of Maps JS API v3.65), auto-generated zone code
- Services/Categories/Subcategories — Categories index (nested subcategory rows inline), Category/Subcategory create/edit, Services index (category/subcategory filter, pagination), Service create/edit. `service_subcategories` is a dedicated table (not self-referencing categories), `services.subcategory_id` is nullable. Replaces Tinker-only seeding.

**Navigation:** icon-rail on the left, showing all planned sections — active ones are clickable, unbuilt ones (Banners, Settings) show as greyed-out placeholders so the full roadmap is visible even before built.

**Not yet built:** Banners management, Settings.

---

## 9. Infrastructure

- **Queue worker:** `onecallfix-worker`, managed by Supervisor, runs continuously — no manual `php artisan queue:work` needed anymore.
- **Backups:** nightly local `mysqldump` (14-day retention, `storage/backups/`, excluded from git) + weekly Hostinger VPS snapshot (Thursdays, built-in).
- **Deployment method:** SCP from a local PC (`scp -r <folder> callf1207@31.97.186.175:/home/1callfix.com/public_html/api/`) — reliable and fast. **Avoid CyberPanel's File Manager for zip uploads into `api/`** — it has a bug where it scans the whole destination tree for symlinks (false-positives on `vendor/bin/*`) and blocks the upload.
- **Known one-time infra bugs already fixed:** OpenLiteSpeed `docRoot` was initially pointed at the project root instead of `public/` (fixed in `/usr/local/lsws/conf/vhosts/api.1callfix.com/vhost.conf`); `User` model needed to extend `Authenticatable`, not plain `Model`, for session login to work.

---

## 10. App Naming (finalized)

| App | Name | Package ID |
|---|---|---|
| Customer app | **1CallFix** | `com.call.customer` (already live on Play Store) |
| Vendor/Provider app | **1CallFix Partner** | `com.call.partner` |
| Delivery/Rider app (Phase 5+, once Parcel/Food exist) | **1CallFix Rider** | `com.call.rider` |

---

## 11. Reference Materials — Use Correctly

- **Glover** (`1CALLFIX/version-1.8.40` on GitHub, and a local `1.8.5 Glover` folder) — 1CallFix's existing multi-vendor codebase. Studied early on for dispatch logic, schema patterns, vendor-type structure. **Reference only, never a build foundation for this project.**
- **6amMart** (commercial template, local folder `6amMart Main app V 4.0.1`) — richer reference than Glover for the 7 unbuilt verticals (Food, Grocery, Pharmacy, Commerce, Parcel, Taxi, Bookings) and for admin panel UX patterns (module switcher, icon-rail nav, operational funnel widgets — several of which have already been adapted into our own admin panel). **Reference only.**
- **A local folder `files for claude code`** exists from a disconnected, confused Claude Code session that had no context on the real project. It wrote no code — safe to ignore entirely. Do not use it as a starting point.

**Rule going forward: any AI tool or developer working on this project must clone the real repo (`1CALLFIX/1callfix-demo`) and read this document — not infer architecture from local template folders.**

---

## 12. What's Next (in order)

1. **Banners management** — admin UI for the ad-revenue banner system (schema already built)
2. **Settings screen**
3. **M6/M7** — the two Flutter mobile apps
4. Only after Service vertical has real, proven revenue: begin Phase 5+ (Parcel, then Food, etc.)
