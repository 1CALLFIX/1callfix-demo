# 1CallFix — Current Master Checkpoint

**This document supersedes the previous read-only audit conversation.** It reflects the repository as of this session. Where it conflicts with `PROJECT_CURRENT_STATE.md`, this document is more current for the specific items it covers; `PROJECT_CURRENT_STATE.md` remains authoritative for everything else until it is next synced.

Labels: **IMPLEMENTED** shipped and wired · **INTEGRATED** wired into the real flow it serves · **TESTED** covered by an automated test · **DOCUMENTED** described in a `.md` file · **PARTIAL** exists but incomplete · **MISSING** does not exist · **BUSINESS DECISION** needs a human call, not an engineering one.

---

## 1. Git state at the start of this session

- **HEAD at session start:** `a2c443a`
- **HEAD now:** `53d6203` — three new commits (`ce46e3f`, `09061a2`, `53d6203`), all local to `main`, none pushed or deployed. Production remains `ba0635a`, untouched throughout.
- **Full suite progression, verified before every commit (not assumed):** 163/163 → 166/166 → 173/173, 0 failures/errors/warnings at each checkpoint.

## 2. This session's three commits

**`ce46e3f` — Notification isolation + Group A carry-forward**
The reported `BookingOtpDeliveryTest` failure traced to `AcceptBookingAction`'s `BookingStatusNotification` send being the one customer notification NOT wrapped in the codebase's own established try/catch-and-log pattern — a real channel-adapter failure crashed the entire acceptance flow before either guarded OTP send ran. Fixed by extracting `sendStatusNotification()` alongside the existing `sendOtpNotification()`. Test's own `Log::shouldHaveReceived('error')` count corrected from `->twice()` to `->times(3)` to match the now-complete, verified behavior (all three post-transaction notifications isolated, not two). Also verified and preserved the two Group A fixes already in the working tree (`Auth\Login` scoped-admin access, `Providers\Show` KYC authorization).

**`09061a2` — Systemic RBAC view-permission enforcement** [IMPLEMENTED] [VERIFIED]
A full sweep of every `hasPermission()` call site in `app/Livewire` (28 components) found mutating actions universally authorized, but 12 seeded `.view` permissions (`dashboard.view`, `bookings.view`, `providers.view`, `workers.view`, `commissions.view`, `wallets.view`, `loyalty.view`, `customers.view`, `subscriptions.view`, `plans.view`, `notification.view`) were never actually checked anywhere — confirmed by reading each permission's own seeding migration, several of which explicitly commented "this screen should check it." Any actor clearing `EnsureHasAdminAccess` could view full cross-franchise commission splits, the wallet ledger, loyalty/referral data, and every customer's PII regardless of role/scope. Fixed with a `mount()`-level `hasPermissionAnywhere()` gate on all 15 affected screens. New `ScreenViewAuthorizationTest` (3 tests × 15 screens × deny/allow/super-admin-bypass = 45 assertions). Two pre-existing RBAC test fixtures corrected to grant the realistic permission pairing their actors were missing.

**Known, explicitly-flagged limitation of this fix:** the gate is `hasPermissionAnywhere()` ("holds the permission somewhere"), not per-row scope filtering — a Zone Admin holding `commissions.view` scoped to their own zone can now enter the Commissions screen, but still sees every franchise's commissions, not just their own zone's. None of these 15 screens filter query results by the viewer's scope today (pre-existing behavior, not introduced by this fix). Real per-row scoping is a separate, larger enhancement — not invented or guessed at here.

**`53d6203` — Operations/Troubleshoot admin screen** [IMPLEMENTED] [VERIFIED]
The mission's own special instruction named this as a specific investigation target. Confirmed genuinely absent via grep sweep (only one false-positive match for "health"/"operations"/"failed_jobs" across all of `app/`). Built `/admin/operations`: failed-job list with Retry (Laravel's own `queue:retry`) and Discard, last 50 `notification_logs` failures (already populated in production via `AppServiceProvider`'s existing `NotificationSent`/`NotificationFailed` listeners — a real, pre-existing audit trail nothing had ever surfaced), and a health panel (DB connectivity, queue/cache/mail driver, SMS/push provider — explicitly flags the dev-only `LogSmsAdapter`/`LogPushAdapter`, storage writability, maintenance-mode state). New `operations.view`/`operations.manage` permissions, super-admin-only by default (same pattern as every prior permission round). Deliberately excludes a "recent exceptions" panel — no Sentry/Flare/Telescope-class package is installed in this codebase (checked `composer.json` directly), so faking that data or silently picking a new dependency was avoided; flagged as a real gap instead. `OperationsHealthTest`, 7 tests / 11 assertions.

## 3. Audited this session, not changed (real evidence, not guesses)

See `EXACT_NEXT_TASK.md` §"What was audited but NOT changed" for the full writeup (Payment Gateway maturity/gaps, Referral engine's real Customer-only scope, Campaign engine's real notification-broadcast-only scope, Badge engine's real KYC-pivot-only scope, Chat's dormant unused model, Tips/Compensation's total absence, Printing's confirmed absence).

## 4. Everything else in the full mission (Groups B–F, per the mission brief's 31 phases)

**Not started this session**, same honest boundary as every prior checkpoint: a full-fledged Referral/Campaign/Tip/Chat/Printing/Admin-design-system/Glover-parity program is realistically many independent, separately-reviewable engineering slices — attempting all of them in one unverified pass would risk exactly the "false claims of completion" every version of this mission has explicitly forbidden. Sequenced honestly in `EXACT_NEXT_TASK.md`'s "Exact next action" section.

## 5. Do not confuse these

- All three commits above are **COMMITTED** to local `main` and **TEST-VERIFIED** (full suite, not just their own filter, run before each commit).
- Nothing this session is **PUSHED**, **DEPLOYED**, or **PRODUCTION VERIFIED** — production remains `ba0635a`, untouched, exactly as instructed.
- The 28 remaining mission phases are **NOT STARTED, NOT CLAIMED COMPLETE** — see `EXACT_NEXT_TASK.md`'s 5-category breakdown for exactly what's buildable now vs. genuinely blocked on a business/vendor decision.
