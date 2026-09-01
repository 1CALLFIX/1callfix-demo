# PHASE PW1 — Provider Web, P1 Implementation Plan

**Status:** BUILT 2026-09-01 (this plan's §1–§10). NOT committed, NOT deployed.
Full suite green — 2107 pass / 5993 assertions; `tests/Feature/ProviderWeb/`
= 41 pass across 7 files; `tests/Feature/Dispatch/DispatchOfferTimeoutConfigTest.php`
covers §1.

**Delivered vs. plan:**
- §1 config `dispatch.offer_timeout_seconds` 25→50 — applied to the dev sqlite as
  a `settings` row; staging/prod still to set via Admin → Settings → Dispatch.
- §2 `EnsureIsProvider` + `bootstrap/app.php` branch + `Provider\Auth\Login` +
  `components/layouts/provider.blade.php` + `provider.logout` + customer-header
  cross-link.
- §3 `SetProviderOnlineStatusAction` (the one new business-logic class) +
  `Provider\Dashboard` (toggle, geolocation capture + 120s heartbeat, eligibility
  panel).
- §4/§6 `Provider\Jobs\Index` (poll, Accept, Decline) + `Provider\Jobs\Show`
  (start/completion OTP, timeline, 404 ownership guard).
- §7 `Provider\Earnings` + `Provider\History`; §10 `Provider\Activity` (merged
  feed); §9 `DetectsStuckJob` trait + dashboard strip + job-screen nudge.
- §11 (Stage 10 good-standing gate) — **RESOLVED 2026-09-01: warn only.** No
  disqualification logic. The eligibility panel (§3.3) is the whole mechanism;
  the online toggle and Accept are never gated on it (the only hard stops are the
  ones `AcceptBookingAction` / `DispatchService` already enforce themselves, e.g.
  the wallet floor, surfaced as an inline message). Nothing further to build.

**Not built (deferred to P2, per §9 / §17):** provider-initiated job release,
`DeclineJobOfferAction` as a real Action, Google/Firebase login buttons,
live-location ping loop, real-time transport.

---

**Original plan follows.** Supersedes the P1 section of
`PHASE_PW_PROVIDER_WEB_DISCOVERY.md` with a build-ready breakdown.

**Decisions locked (from the discovery review):**
1. **Shared `web` session** across customer and provider areas — no forced-separate
   login. `/provider/*` is gated by profile existence, not a separate guard.
2. **Location-fix visibility is P1, not deferred.** A provider is never silently
   invisible to dispatch — the dashboard names every reason they will not receive
   jobs.
3. **`provider_en_route` is skipped for P1.** Flow is `assigned` → (start-OTP) →
   `in_progress` → (completion-OTP) → `completed`, identical to every existing
   provider path.
4. **`dispatch.offer_timeout_seconds` 25 → 50** is a standalone config change, done
   separately and first — see §1.

**The one new business-logic class in P1: `SetProviderOnlineStatusAction`.**
Everything else is Livewire view-layer components calling Actions/Services that
already exist, one thin middleware, and read-only queries.

---

## 1. Standalone config change (do this first, separately)

**Change:** `dispatch.offer_timeout_seconds` from its code default `25` to `50`.

**Confirmed: Setting value change only. No deploy, no migration, no code edit, no
restart.**

- `ServiceMatchingJob::offerTimeoutSeconds()` reads
  `Setting::get('dispatch.offer_timeout_seconds', 25)` live on every run
  (`app/Jobs/ServiceMatchingJob.php:56-59`). There is **no** `config/*.php` entry
  and **no** seeder for it — the `25` is a pure in-code fallback used only when no
  `settings` row exists.
- `settings` is DB-backed, `CACHE_STORE=database` (shared across workers), and
  `Setting::set()` calls `cache()->forget()` on exactly the resolved key
  (`app/Models/Setting.php:68-72`). So the new value is live for every queue
  worker on its next job — no `queue:restart` required (harmless if run anyway).
- Apply via **Admin → Settings → Dispatch**, "Offer timeout seconds" field, at
  **global** scope (`Livewire\Settings\Manage` already renders and persists this
  field — `Manage.php:377`, `:531`). Equivalent one-liner:
  `Setting::set('dispatch.offer_timeout_seconds', '50')`.
- `NewJobOffered::broadcastWith()` hardcodes `expires_in_seconds: 25`
  (`app/Events/NewJobOffered.php`). Cosmetic only today (`BROADCAST_CONNECTION=log`
  — nothing consumes the event). Leave it; fix if/when a real transport lands (P3).
- Side effect to note, not to fix: a longer window means a booking with zero
  available providers takes `max_rounds` (6) × 50s ≈ 5 min instead of ~2.5 min to
  reach the admin manual-assignment fallback. Acceptable; call it out to Ops.

**Verification:** set the row, then confirm one dispatch round holds an offer for
~50s before `timeoutExpiredAttempts()` flips it (a `tinker` dispatch of
`ServiceMatchingJob` against a seeded searching booking, or observe on staging).

---

## 2. Auth & shell

### 2.1 `EnsureIsProvider` middleware (new — a gate, not business logic)

Direct mirror of `app/Http/Middleware/EnsureHasAdminAccess.php`:

```
$isProvider = $request->user() && $request->user()->providerProfile()->exists();
abort_unless($isProvider, 403, 'This area is for 1CallFix service partners.');
```

Register in `bootstrap/app.php` middleware aliases (same place `EnsureHasAdminAccess`
is referenced from `routes/admin.php`). It answers only "is this user a provider at
all" — per-screen concerns (KYC, active) are shown, not blocked, by the dashboard
(§3.3).

### 2.2 `bootstrap/app.php` — guest redirect branch

`redirectGuestsTo` currently forks admin vs customer. Add a provider branch:

```
$request->is('provider', 'provider/*') ? route('provider.login')
  : ($request->is('admin', 'admin/*') ? route('admin.login') : route('customer.login'))
```

### 2.3 `ProviderLogin` Livewire component (new — view layer)

Copy of `app/Livewire/Customer/Auth/Login.php` with three deltas:

- `use InteractsWithAuthThrottle` and `CustomerAccountResolver` — **unchanged**,
  reused as-is (both are role-agnostic).
- After `Auth::guard('web')->login($user)`: if `! $user->providerProfile()->exists()`
  → `Auth::guard('web')->logout()`, set `$this->error = 'That account is not a
  registered service partner.'`, return. (A customer who wanders to `/provider/login`
  gets a clear message, not a half-session.)
- Redirect target `provider.dashboard` instead of `customer.home`.
- Password-migration fork (`blank($user->password)` → `customer.auth.migrate`) is
  kept verbatim — a legacy OTP-only provider sets a password through the same
  one-time flow, then lands back at `/provider/login`.
- Google / Firebase-phone buttons: **omitted in P1** (password covers every
  existing provider row). The `#[On('firebase-google-token')]` handler is a
  copy-paste from `Login.php` when wanted (P3).

### 2.4 `provider.logout` route

Mirror of `customer.logout` in `routes/web.php:107-113` — POST-only, CSRF,
`Auth::guard('web')->logout()` + `session()->invalidate()` +
`regenerateToken()`, redirect to `provider.login`.

### 2.5 Provider layout

New `resources/views/components/layouts/provider.blade.php` — forked from
`customer.blade.php`, stripped to a provider top bar (logo, online/offline pill,
"Jobs" / "Earnings" / "Activity" nav, logout). No cart, no wallet-topup, no
customer bottom-nav. Every provider component renders
`->layout('components.layouts.provider', [...])`.

**Cross-link:** in the customer layout, show a "Partner dashboard →" link when
`auth()->user()?->providerProfile` exists (satisfies decision 1 — one session,
both areas reachable).

---

## 3. Online / offline toggle + location-fix visibility

### 3.1 `SetProviderOnlineStatusAction` — THE one new business-logic class

`app/Actions/SetProviderOnlineStatusAction.php`

```
execute(Provider $provider, bool $online, ?float $lat = null, ?float $lng = null): Provider
```

Responsibilities (single `DB::transaction`, `Provider::whereKey()->lockForUpdate()`):

- Set `is_online = $online`.
- If `$lat` and `$lng` are both present and finite: set `current_lat`, `current_lng`,
  and always stamp `location_updated_at = now()`.
- If going online **without** coordinates: still set `is_online = true`, leave
  `current_lat/lng` as-is (may be null or stale), stamp nothing new — the
  dashboard (§3.3) then shows the "no location fix" state.
- If going offline: set `is_online = false`; leave last known coordinates
  untouched (audit/debug value; dispatch already ignores an offline provider).
- Write an audit row: `ActivityLogger::logModel(auth()->user(), $provider,
  $online ? 'Went online (web)' : 'Went offline (web)', ['lat' => $lat, 'lng' => $lng])`.
  `activity_log` exists and `ActivityLogger` is the sanctioned writer
  (`app/Services/ActivityLogger.php`) — provider online/offline is a genuinely new,
  useful audit signal and the same shape Operations already writes.
- Return `$provider->fresh()`.

Does **not** touch KYC, `is_active`, skills, zone — those are admin-owned.
Does **not** emit a broadcast (nothing consumes one).

### 3.2 Toggle UI (`ProviderDashboard`, view layer)

- A prominent Online / Offline switch. On toggle-**on**, the blade's JS first
  requests `navigator.geolocation.getCurrentPosition()`:
  - success → `$wire.goOnline(lat, lng)` → `SetProviderOnlineStatusAction(.., true, lat, lng)`
  - denied / unavailable / timeout → `$wire.goOnline(null, null)` → action still
    sets `is_online = true`; dashboard immediately renders the "no location" warning.
- On toggle-**off** → `$wire.goOffline()` → `SetProviderOnlineStatusAction(.., false)`.
- **Heartbeat while online:** a `wire:poll.120s` (or a JS `setInterval`) re-runs
  `getCurrentPosition()` and calls `goOnline(lat, lng)` again to refresh
  `current_lat/lng` + `location_updated_at`. Foreground-tab only (browser throttles
  hidden tabs — documented constraint, same as the offers screen).

### 3.3 Eligibility panel — "why you will / won't get jobs" (decision 2)

Read-only, computed in `ProviderDashboard::render()` straight from the provider row
+ the exact predicates `DispatchService::eligibleQuery()` /
`findCandidates()` apply (`app/Services/DispatchService.php:437-447, 45-81`). One
line per check, green tick or red cross with plain-language fix text:

| Check | Source | Red-state message |
| --- | --- | --- |
| Online | `is_online` | "You're offline — you won't be offered any jobs." |
| **Location fix** | `current_lat` & `current_lng` not null | "You're online but we don't have your location — **you will not receive jobs.** Allow location access and toggle online again." |
| Location fresh | `location_updated_at` within N min (default 30, advisory) | "Your last location fix was 45 min ago — it may be inaccurate. Refresh." |
| KYC approved | `kyc_status === 'approved'` | "Your KYC is `{status}`. Jobs resume once an admin approves it." |
| Account active | `is_active` | "Your account is inactive. Contact support." |
| Has a skill | `skills` non-empty array | "No service categories on your profile yet — an admin sets these." |
| Zone assigned | `zone_id` not null | "No zone assigned — an admin sets this." |

Notes:
- `current_lat/lng` NULL is a **hard** dispatch exclusion today
  (`whereNotNull('current_lat')`); message says so in absolute terms.
- `location_updated_at` staleness is **not** a dispatch filter today — the row is
  advisory only. Making it a hard filter is a `DispatchService` change and is
  **out of P1 scope** (flag for a later dispatch phase).
- KYC / active / skills / zone red states do not block entry to `/provider/*`
  (the middleware only checks profile existence); they block *work*, and the panel
  explains that.

---

## 4. Job offers list

### 4.1 `ProviderJobs` component (`GET /provider/jobs`, view layer)

`wire:poll.4s` on the offers region (tighter than the customer tracker's 6s —
the actionable window is short even at 50s).

**Offers query** (no Action — a read):

```
DispatchAttempt::where('provider_id', $provider->id)
    ->where('status', 'notified')
    ->where('notified_at', '>=', now()->subSeconds( (int) Setting::get('dispatch.offer_timeout_seconds', 25) ))
    ->with(['booking.service', 'booking.address', 'booking.customer:id,name'])
    ->latest('notified_at')
    ->get()
```

The `notified_at` floor mirrors `ServiceMatchingJob::timeoutExpiredAttempts()` so a
row the job hasn't flipped to `timeout` yet doesn't linger on screen as live.

**Per offer card:** service name, subcategory, quoted price (`booking.price_quoted`),
distance (`dispatch_attempts.distance_km`), scheduled-at (or "ASAP"), address area
(not full address until accepted), and a countdown derived from
`notified_at + offer_timeout_seconds`.

**Active job banner:** if this provider holds a booking in
`['assigned','in_progress']`, show it at the top with a link to `/provider/jobs/{id}`
(§6). Guarantees an accepted job is never off-screen (feeds §9).

### 4.2 Accept

Button → `AcceptBookingAction::execute($bookingId, $provider)` — **verbatim** the
call `API\DispatchController::accept()` makes
(`app/Http/Controllers/API/DispatchController.php:26`). It already handles: the
accept/accept race (row locks), the `wallet.provider_min_balance_to_accept_jobs`
gate, `scheduled_at` overlap check, OTP generation + `stampFresh`, timing out the
sibling offers, and bundle consolidation. On `\RuntimeException` show the message
inline ("already taken", "wallet below minimum", "overlaps another job") and
refresh the list. On success redirect to `/provider/jobs/{booking}`.

### 4.3 Decline / skip

Minimal, **no new class** — a guarded component method:

```
$attempt = DispatchAttempt::where('booking_id', $id)
    ->where('provider_id', $provider->id)->where('status', 'notified')->first();
abort_unless($attempt, 404);
$attempt->update(['status' => 'rejected', 'responded_at' => now()]);
```

- `dispatch_attempts.status = 'rejected'` is already defined in the schema and
  already treated as a **permanent** per-booking exclusion by
  `DispatchService::excludedProviderIdsForBooking()`
  (`app/Services/DispatchService.php:119-136`) — nothing else in the codebase writes
  it; this becomes its first writer.
- The booking stays `searching_provider`; the next `ServiceMatchingJob` round skips
  this provider for this booking. No booking-FSM change, no money, no notification —
  which is why it does not warrant an Action (the one deliberate exception to the
  "every state write is an Action" convention; documented here).
- Alternative if you'd rather: omit Decline entirely in P1 and let offers lapse.
  Recommended to keep it — an explicit "not now" is better dispatch signal than a
  timeout, and it powers `ProviderAnomalyService` honestly later.

---

## 5. (folded into §4 and §6)

---

## 6. Active job screen

### 6.1 `ProviderJobShow` (`GET /provider/jobs/{booking}`, view layer)

`mount()`: `abort_unless($booking->provider_id === $provider->id, 404)` — the same
404-not-403 information-hiding convention `Customer\Orders\Show::mount()` uses.
`#[Locked]` the id.

**Shows** (all real columns, nothing computed):
- Customer name + phone (`booking.customer`), full address + `address_line` +
  lat/lng map link (`booking.address`) — revealed now that the job is theirs.
- Service, options snapshot, quoted price, scheduled window.
- Status timeline from `booking_status_history` (see §10).
- The correct OTP entry field for the current status (below).

`wire:poll.10s` while status ∈ `['assigned','in_progress']` so a customer-side
cancel (`AdminCancelBookingAction` from the customer app) reflects promptly.

### 6.2 Start-OTP (status `assigned`)

Field + submit → `StartBookingAction::execute($booking->id, $enteredOtp,
auth()->id())` — **verbatim** the call `WorkerJobController::start()` makes
(`app/Http/Controllers/API/WorkerJobController.php:55`). `BookingOtpService`
enforces expiry / attempt-cap / single-use inside it. On `BookingOtpException` /
`\RuntimeException` show the message ("Incorrect start OTP", "expired", "Too many
attempts — ask an admin to reissue") inline; the action already committed the
attempt-counter increment separately. On success the component re-renders into the
`in_progress` state.

`provider_en_route` is not offered (decision 3). `StartBookingAction` accepts only
`['assigned','provider_en_route']` — from web we always pass `assigned`.

### 6.3 Completion-OTP (status `in_progress`; also accepts `assigned`)

Field + submit → `CompleteBookingAction::execute($booking->id, $provider,
$enteredOtp)` — **verbatim** the call `API\DispatchController::complete()` makes
(`app/Http/Controllers/API/DispatchController.php:60`). It does the completion
transaction, commission split, wallet credit, loyalty, referral, receipt
materialisation and bundle-latch — none re-implemented here. Same error handling as
§6.2. On success → "Job completed, ₹{provider_commission} added to your wallet"
(read the fresh `commissions` row) and a link back to `/provider/jobs`.

Note: `CompleteBookingAction` tolerates `assigned` directly, so a provider who
never ran Start can still complete — matches today's behaviour; the UI just leads
with Start when status is `assigned`.

---

## 7. Earnings, job history, wallet transactions

`ProviderEarnings` (`GET /provider/earnings`) and `ProviderHistory`
(`GET /provider/history`) — both **read-only**, no Action, no new query object.

### 7.1 Earnings summary

- **Balance:** `WalletService::balance($provider->user)` (`app/Services/WalletService.php:32`).
- **Per-job earnings:** `Commission` rows joined via `bookings.provider_id`:
  `Commission::whereHas('booking', fn($q) => $q->where('provider_id', $provider->id))
  ->with('booking:id,code,completed_at,price_final')->latest()` → show
  `provider_commission` per completed job, with date filter (this week / month /
  custom). Totals are `sum('provider_commission')` over the range.
- **Payouts:** if a payout/withdrawal surface is wanted, that's a separate model
  (`payouts` / `Payouts\Manage` is admin-side) — **out of P1**; earnings screen is
  "what you've earned + current wallet", not "withdraw".

### 7.2 Job history

`Booking::where('provider_id', $provider->id)->with('service:id,name',
'address:id,label')->latest()->paginate(15)`, filter by status bucket — the exact
shape `Customer\Orders\Index` uses (`app/Livewire/Customer/Orders/Index.php`),
pointed at `provider_id` instead of `customer_id`. Each row links to
`/provider/jobs/{id}` (§6) for completed/active, read-only for terminal.

### 7.3 Wallet transaction history

`WalletTransaction` for this provider's wallet:
`WalletTransaction::whereHas('wallet', fn($q) => $q->where('user_id',
$provider->user_id))->latest()->paginate(20)` — show `amount`, `is_credit`,
`reason` (e.g. `booking_commission`), `created_at`, `status`. Read-only ledger,
same as `Customer\Wallet\Index` minus top-up.

---

## 8. (folded into §7)

---

## 9. Stuck-accepted-job detection & self-recovery

**The gap:** once a provider accepts, the offer/timeout machinery stops applying.
If they close the tab, lose connectivity, or just forget, the booking sits in
`assigned` / `in_progress` indefinitely from the provider's side with nothing
nudging them and no self-serve way to hand it back.

**What already exists (admin-side, reuse the primitives — do not build a new
engine):**
- `StuckBookingService` (`app/Services/Operations/StuckBookingService.php`)
  already classifies stuck bookings by status against Setting-driven thresholds —
  `assigned` 60 min, `in_progress` 240 min, `on_hold` 24 h — reading
  `booking_status_history.changed_at` for "when it entered this status". Read-only;
  recovery is via `AdminReassignBookingAction` / `AdminCancelBookingAction` on
  `Bookings\Show`.
- `DispatchHealthService` surfaces stale offers + exhausted searches for Ops.
- The admin Operations → Health screen (`Livewire\Operations\Health`) already
  renders both.

**P1 scope — provider-facing surface only (pure read, no new mutation):**

1. **Always-visible active job** — the `/provider/jobs` active-job banner (§4.1)
   and a matching item on `/provider` dashboard. An accepted job can never be
   off-screen. This alone closes most of the gap.
2. **Age nudge on `ProviderJobShow`** — compute "entered current status N ago"
   from `booking_status_history` (same `statusEnteredAt()` logic as
   `StuckBookingService`, thresholds read from the same
   `operations.stuck_threshold_minutes.{status}` Settings keys — no new keys). When
   over threshold, show a banner: *"You accepted this 1 h 20 m ago and haven't
   started it. Start it now, or contact your dispatcher if you can't complete it."*
3. **Dashboard "needs attention" strip** — if this provider holds any booking
   over the `assigned` / `in_progress` threshold, show a persistent strip on
   `/provider` linking to it.
4. **Idle-online reconciliation (read):** if `is_online = true` but
   `location_updated_at` is older than the heartbeat interval × 3 (tab clearly
   gone), the dashboard shows *"We may have lost contact with your device — you
   could still be holding jobs. Reopen this tab or go offline."* Still just a
   rendered state, no auto-write.

**Explicitly deferred to P2 (needs its own small decision):**
- **Provider-initiated "release this job"** — hand an accepted booking back to
  `searching_provider`. The mechanism exists (`AdminReassignBookingAction` /
  `AdminCancelBookingAction`), and the E6 precedent is "reuse the admin Action
  behind an ownership check the admin doesn't need". But letting a provider
  self-release has real abuse/UX questions (partial-work billing, repeat
  offenders, customer already waiting) — so P1 surfaces the problem and routes to
  the dispatcher; the self-release control is a P2 item with a decision attached.
- Auto-reclaim / auto-offline of a provider whose device went dark — a scheduled
  job; belongs with the broader dispatch-health work, not the provider web P1.

---

## 10. Activity log surfacing

No new table. A provider's "activity" is the union of three tables already written
by real code paths, rendered as one reverse-chronological feed:

| Source | Rows | Written by |
| --- | --- | --- |
| `booking_status_history` | per-job transitions (`assigned` / `in_progress` / `completed` / `cancelled`) with `changed_at`, `changed_by`, `note` | every booking Action's `statusHistory()->create()` |
| `wallet_transactions` | commission credits, any debits | `WalletService` (via `CommissionService`) |
| `dispatch_attempts` | offers received, accepted, timed out, (now) declined | `ServiceMatchingJob`, `AcceptBookingAction`, §4.3 |
| `activity_log` (optional) | web online/offline toggles | `SetProviderOnlineStatusAction` (§3.1) |

- **Per-job timeline** on `ProviderJobShow` (§6.1): just this booking's
  `booking_status_history` rows, the same visual as `Customer\Orders\Show`'s
  timeline.
- **Account-wide feed** on `/provider/activity` (`ProviderActivity`, view layer):
  merge the four sources for this provider, cap at ~100 rows, page back by date.
  Scope every query hard to `provider_id` / `wallet.user_id` /
  `dispatch_attempts.provider_id`.
- All read-only. `ActivityLogger` stays the only writer to `activity_log`, and
  only §3.1 adds to it.

---

## 11. Stage 10 — good-standing / eligibility gate — RESOLVED: warn only

**Decision (2026-09-01): warn only. No disqualification logic, now or in P2.**

The eligibility panel (§3.3) is the entire mechanism. It names every reason
dispatch will or won't reach the provider — offline, no location fix, KYC not
approved, account inactive, no skills, no zone, stale location — in plain
language, and the "no location fix" case is stated in absolute terms ("you will
NOT receive jobs"). Nothing on the provider web is *gated* on any of it:

- The online/offline toggle always works.
- Accept always attempts the real `AcceptBookingAction`; the only hard stops are
  the ones that Action / `DispatchService` already enforce on their own — the
  `wallet.provider_min_balance_to_accept_jobs` floor, KYC/active/location checks
  in `eligibleQuery()` — and the Action's own message is surfaced inline when it
  refuses.
- `ProviderAnomalyService` findings, repeated §9 stuck-job incidents, `is_active`,
  `kyc_status` — none auto-restrict the provider web. They inform the panel; an
  admin acts on them through the existing admin surfaces.

No further work. The current build already matches this decision — it was written
warn-only from the start.

---

## 12. Routes (final P1 set)

`routes/web.php`, added above `require __DIR__.'/admin.php'`:

| Method | Path | Name | Middleware | Component |
| --- | --- | --- | --- | --- |
| GET | `/provider/login` | `provider.login` | `guest` | `ProviderLogin` |
| POST | `/provider/logout` | `provider.logout` | `auth` | closure (mirror `customer.logout`) |
| GET | `/provider` | `provider.dashboard` | `auth`, `EnsureIsProvider` | `ProviderDashboard` |
| GET | `/provider/jobs` | `provider.jobs.index` | `auth`, `EnsureIsProvider` | `ProviderJobs` |
| GET | `/provider/jobs/{booking}` | `provider.jobs.show` | `auth`, `EnsureIsProvider` | `ProviderJobShow` |
| GET | `/provider/earnings` | `provider.earnings` | `auth`, `EnsureIsProvider` | `ProviderEarnings` |
| GET | `/provider/history` | `provider.history` | `auth`, `EnsureIsProvider` | `ProviderHistory` |
| GET | `/provider/activity` | `provider.activity` | `auth`, `EnsureIsProvider` | `ProviderActivity` |

Online/offline and decline are Livewire actions on their components — no dedicated
POST routes. **No collisions:** `/admin/providers` and `/api/providers/*` do not
touch the `/provider` root; the `provider.*` route-name namespace is unused.

---

## 13. Data-model touchpoints

**Writes** (all through existing paths except where noted):

| Table | Column(s) | Path |
| --- | --- | --- |
| `providers` | `is_online`, `current_lat`, `current_lng`, `location_updated_at` | **`SetProviderOnlineStatusAction` (new)** |
| `dispatch_attempts` | `status` → `accepted` (+ siblings `timeout`) | `AcceptBookingAction` (existing) |
| `dispatch_attempts` | `status` → `rejected`, `responded_at` | §4.3 guarded component method (new writer of an existing column/value) |
| `bookings` + `booking_status_history` + `commissions` + `wallet_transactions` | full completion side effects | `StartBookingAction` / `CompleteBookingAction` (existing) |
| `activity_log` | one row per online/offline toggle | `ActivityLogger::logModel` via `SetProviderOnlineStatusAction` |

**No migrations in P1.** Every column already exists (`providers.is_online`,
`current_lat`, `current_lng`, `location_updated_at` — `app/Models/Provider.php:29-33`;
`dispatch_attempts.status`, `responded_at`; `activity_log` — Phase P3 migration).

**New Setting keys:** none required. Reuses `dispatch.offer_timeout_seconds`,
`dispatch.max_timeouts_per_provider`, `operations.stuck_threshold_minutes.{status}`.
One **optional** advisory key for §3.3 location freshness, e.g.
`provider.location_stale_after_minutes` (default 30) — advisory display only.

---

## 14. Test plan (`tests/Feature/ProviderWeb/*`)

Mirror the E6 test discipline (`tests/Feature/CustomerWeb/*` — feature tests +
one E2E, plus a browser walk-through).

1. **`ProviderAuthTest`** — `/provider/*` 403s for a customer (no
   `providerProfile`); `/provider/login` rejects + logs out a non-provider;
   password-less provider is redirected to the migration flow; a valid provider
   lands on `provider.dashboard`; shared session — a provider can still open
   `/orders`.
2. **`ProviderOnlineToggleTest`** — `SetProviderOnlineStatusAction`: online with
   coords sets all four columns + `location_updated_at` + an `activity_log` row;
   online without coords sets `is_online` only and the dashboard renders the
   "no location — you will not receive jobs" state; offline flips the flag; the
   eligibility panel shows the right red state for each of null-location /
   `kyc_status != approved` / `is_active = false` / empty `skills` / null `zone_id`.
3. **`ProviderOffersTest`** — after `ServiceMatchingJob` runs, a `notified`
   `dispatch_attempts` row for this provider appears on `/provider/jobs`; a row
   older than `dispatch.offer_timeout_seconds` does not; Accept →
   `AcceptBookingAction` path → booking `assigned`, sibling offers `timeout`,
   OTPs generated; a second provider accepting the same booking gets the
   "already assigned" message; Decline writes `status = 'rejected'` and the next
   `DispatchService::findCandidates()` round excludes this provider for that
   booking.
4. **`ProviderJobLifecycleTest`** — start-OTP wrong code → `BookingOtpException`,
   attempt counter incremented, booking still `assigned`; correct code →
   `in_progress`; completion-OTP correct → `completed`, `commissions` +
   `wallet_transactions` rows written, "added to your wallet" reflects
   `provider_commission`; ownership guard — provider B gets 404 on provider A's
   job.
5. **`ProviderEarningsHistoryTest`** — earnings totals = `sum(provider_commission)`
   over range; job history scoped to `provider_id`; wallet ledger scoped to the
   provider's `wallet.user_id`; activity feed merges status-history + wallet +
   dispatch rows in date order, all provider-scoped.
6. **`ProviderStuckJobTest`** — a booking held in `assigned` past the
   `operations.stuck_threshold_minutes.assigned` Setting shows the age nudge on
   `ProviderJobShow` and the "needs attention" strip on `/provider`; the active
   job is always present on `/provider/jobs` regardless of age; no mutation occurs.
7. **`ProviderJourneyPW1Test`** (E2E) — register-less existing provider logs in →
   goes online (with coords) → receives a seeded offer → accepts → opens the job →
   start-OTP → completion-OTP → sees the commission on earnings and the events on
   the activity feed → goes offline.

Plus: full existing suite stays green (no existing file is modified except
`routes/web.php`, `bootstrap/app.php`, and the customer layout's one added link).

---

## 15. Build / rollout order

1. **§1 config change** — `dispatch.offer_timeout_seconds = 50`, global, via Admin
   Settings. Standalone, before any code. Verify one round holds ~50s.
2. `EnsureIsProvider` + `bootstrap/app.php` redirect branch + `provider` layout +
   `ProviderLogin` + `provider.logout` + customer-layout cross-link. (§2)
3. `SetProviderOnlineStatusAction` + `ProviderDashboard` (toggle + eligibility
   panel + heartbeat). (§3) — the one new business-logic class, tested in
   isolation first.
4. `ProviderJobs` (offers list, poll, Accept, Decline) + `ProviderJobShow`
   (active job, start-OTP, completion-OTP). (§4, §6)
5. `ProviderEarnings` + `ProviderHistory` + wallet ledger. (§7)
6. `ProviderActivity` + per-job timeline on `ProviderJobShow`. (§10)
7. §9 stuck-job surfaces (banner + strip + age nudge) — read-only, layered on
   §3/§4/§6.
8. Full `tests/Feature/ProviderWeb/*` + regression + browser walk-through.
9. **Hold for the §11 answer** before any good-standing / disqualification work.

---

## 16. Risks & mitigations

| Risk | Mitigation |
| --- | --- |
| Backgrounded tab misses the 4s poll / 50s window | Documented constraint; §3.3 freshness warning; real push is P3. The 25→50 change buys margin now. |
| Browser denies geolocation → provider thinks they're working but gets nothing | §3.3 states it in absolute terms ("you will not receive jobs"); toggle-on flow leads with the permission prompt. |
| Provider accepts then vanishes | §9 surfaces it to the provider and (already) to Ops via `StuckBookingService`; self-release deferred to P2 with a decision. |
| `Setting` cache lag on the timeout change | `CACHE_STORE=database` is shared; `Setting::set()` forgets the key; workers pick it up next job. `queue:restart` optional. |
| A customer+provider user confused by two areas | One session, explicit cross-links both ways, distinct layouts. |
| Decline as a non-Action row write drifts from convention | Scoped to a single owned row, one terminal status value already honoured by `DispatchService`, no side effects; documented exception. Promote to `DeclineJobOfferAction` if it ever grows a side effect. |

---

## 17. Deliberately not implemented in P1

- Any change to `ServiceMatchingJob`, `DispatchService`, `AcceptBookingAction`,
  `StartBookingAction`, `CompleteBookingAction`, `BookingOtpService`,
  `CommissionService` — P1 is a caller.
- `provider_en_route` transition / "on my way" button (decision 3).
- Provider-initiated job release / reassign (P2, needs a decision — §9).
- Good-standing / disqualification gate (§11 — open question).
- `location_updated_at` as a hard dispatch filter (later dispatch phase).
- Real-time transport (Reverb / FCM) — P3.
- Google / Firebase-phone login buttons on `/provider/login` — P3.
- Payout / withdrawal request flow — separate, admin-owned today.
- Provider profile editing (skills / zone / KYC upload) — read-only view only if
  shown at all in P1; edits are a later phase.
- FieldWorker (rider/driver) web — different controller family, out of scope.
