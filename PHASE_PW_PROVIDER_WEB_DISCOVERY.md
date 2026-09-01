# PHASE PW — Provider Web Interface (Discovery)

**Status:** Discovery only. No code, no migrations, no scaffolding. This document
scopes a lightweight authenticated web surface for providers that reuses the
existing dispatch / booking backend, and ends with a phased build list.

**Builds on (all present, none to be modified by this scope):**
- Auth rebuild (`feature/auth-password-rebuild`, `3a7f74a`) — password-first login
  on `users.password`, Firebase phone OTP + Google, `web` session guard.
- Phase E5 (`df04c30`) — `BookingOtpService` hardened start/completion OTP.
- Phase E6 (customer web transactional half) — Livewire on the `web` guard,
  `wire:poll` live-tracking pattern (`resources/views/livewire/customer/orders/show.blade.php:23`).
- Dispatch engine — `ServiceMatchingJob` + `DispatchService` + `dispatch_attempts`.

---

## 1. Auth — reuse the stack, add a thin role gate

### 1.1 What already exists

| Fact | Source |
| --- | --- |
| `users.role` enum includes `provider` | `2026_08_01_003000_create_users_table.php:19`, widened by `2026_08_11_015000_add_admin_roles_to_users_role_enum.php` — full set: `customer, provider, franchise_owner, zone_manager, country_admin, city_admin, operator, support, super_admin` |
| A provider actor = a `users` row (`role='provider'`) **plus** a `providers` row | `User::providerProfile()` — `hasOne(Provider::class)` (`app/Models/User.php:46`) |
| Credential engine is role-agnostic | `CustomerAccountResolver::findByLoginIdentifier()` matches phone/email against **any** `users` row (`app/Services/Auth/CustomerAccountResolver.php:34-56`); it never filters by role |
| The customer `Login` component logs in **any** matched user with no role check | `app/Livewire/Customer/Auth/Login.php:76-89` — `Auth::guard('web')->login($user)` then redirect to `customer.home` |
| Legacy (pre-rebuild, password-less) accounts are funnelled to a set-password flow | `Login.php:68-73` → `customer.auth.migrate`; applies to a legacy provider row identically |
| Signup can only ever mint a **customer** | `CustomerAccountResolver::completeSignup()` / `createFromGoogle()` hardcode `'role' => 'customer'` (`:129`, `:177`). Providers are created by admin / onboarding (`ProviderPreRegister`), never by self-signup |
| API side already resolves a provider from the token holder | `DispatchController::accept()` / `complete()` use `$request->user()->providerProfile` and 403 when absent (`app/Http/Controllers/API/DispatchController.php:19-22, 51-53`) |

### 1.2 Answer

**Providers reuse the existing customer auth stack** — same `users.password` hash,
same optional Firebase phone OTP + Google, same `web` session guard, same
`InteractsWithAuthThrottle` throttle trait, same `CustomerAccountResolver`. There
is **no provider-specific auth backend to build.**

What is provider-specific is **landing + gating**, not identity:

- **New thin `EnsureIsProvider` middleware** — a direct mirror of
  `EnsureHasAdminAccess` (`app/Http/Middleware/EnsureHasAdminAccess.php`), except
  the test is `$request->user()?->providerProfile()->exists()` instead of
  `roleAssignments()->exists()`. Gates every `/provider/*` route. `abort(403)` on a
  logged-in non-provider.
- **New `/provider/login` Livewire component** — ~40 lines, a copy of
  `Customer\Auth\Login` with two changes: (a) after `Auth::guard('web')->login()`
  it refuses (logout + error) a user whose `providerProfile` is null, and
  (b) it redirects to `provider.dashboard` instead of `customer.home`. Reuses the
  throttle trait and `CustomerAccountResolver` unchanged. A separate URL exists for
  redirect target + copy ("Partner sign in"), **not** a separate guard.
- Google / Firebase phone buttons on the provider login are optional for P1 — the
  password path covers every existing provider row. Wire them in P3 if wanted (the
  `#[On('firebase-google-token')]` handler is copy-paste from `Login.php`).

### 1.3 Decision for the user (role is not exclusive)

A single human can hold **both** a `providerProfile` and place bookings as a
customer (`User` has both relations). Two options:

- **(A, recommended) One `web` session, two areas.** After login the user is just
  "authenticated". `/provider/*` is visible/allowed iff `providerProfile` exists;
  `/orders`, `/book/*` etc. stay available too. A header link "Partner dashboard"
  shows when `providerProfile` exists. Least code, no guard juggling.
- **(B) Separate login only.** Force provider work through `/provider/login` and
  show only the provider shell in that session. Cleaner separation, but needs a
  session flag and duplicate logout/redirect handling, and annoys the
  customer-and-provider user. Not recommended for a first version.

`bootstrap/app.php`'s `redirectGuestsTo` already routes non-`admin/*` guests to
`customer.login`; add a `provider/*` branch pointing at `provider.login`.

---

## 2. Screen inventory & exact backend wiring

Legend: **reuse** = calls an existing Action/Service verbatim · **read** = plain
Eloquent query, no business logic · **GAP** = backend genuinely missing.

| # | Screen | Route (proposed) | Backend it calls | Verdict |
| --- | --- | --- | --- | --- |
| 1 | Login | `GET /provider/login` | `CustomerAccountResolver::findByLoginIdentifier()` + `Auth::guard('web')->login()` + `InteractsWithAuthThrottle` | **reuse** (new ~40-line component) |
| 2 | Dashboard + online/offline toggle | `GET /provider`, `POST /provider/online` | Writes `providers.is_online` (+ `location_updated_at`, + optional `current_lat/current_lng`). **No existing code writes `is_online` from any self-service path** — `app/Livewire/Providers/Show.php:179` only *reads* it; `app/Livewire/AllUsers/Index.php:194` explicitly notes the toggle was "deliberately NOT built" | **GAP** — new `SetProviderOnlineStatusAction` |
| 3 | Job-offer list (incoming) | `GET /provider/jobs` (with `wire:poll`) | `DispatchAttempt::where('provider_id', $provider->id)->where('status','notified')` + `->with('booking.service','booking.address')`. `WorkerJobController::index` is the FieldWorker analogue but lists *assigned* jobs, not *offers* — no provider-offer read endpoint exists | **read** (new query in the component; no Action) |
| 4 | Accept offer | action on the offer card | `AcceptBookingAction::execute($bookingId, $provider)` — the exact call `API\DispatchController::accept()` makes (`DispatchController.php:26`). Handles the accept/accept race, wallet-floor gate, OTP generation, sibling-bundle consolidation | **reuse** |
| 4b | Decline offer | action on the offer card | **No action exists.** `dispatch_attempts.status='rejected'` is defined in the schema and already honoured permanently by `DispatchService::excludedProviderIdsForBooking()` (`app/Services/DispatchService.php:119-136`), but nothing writes it | **GAP (optional)** — tiny `DeclineJobOfferAction`; or omit for P1 and let the offer time out |
| 5 | Active job | `GET /provider/jobs/{booking}` | Read booking with `provider_id === $provider->id` guard. Customer address → `booking->address`; contact → `booking->customer` (name/phone). Start-OTP field → `StartBookingAction::execute($bookingId, $otp, auth()->id())` — the exact call `WorkerJobController::start()` makes (`WorkerJobController.php:55`) | **reuse** |
| 6 | Completion | same screen (section revealed once `in_progress`) or `GET /provider/jobs/{booking}#complete` | Completion-OTP field → `CompleteBookingAction::execute($bookingId, $provider, $otp)` — the exact call `API\DispatchController::complete()` makes (`DispatchController.php:60`). Applies commission split, wallet credit, loyalty, receipt | **reuse** |
| 7 | Earnings / history | `GET /provider/earnings`, `GET /provider/history` | Read-only: `Commission::where('booking_id', ...)` for `provider_commission` per completed booking (join via `bookings.provider_id`); current balance `WalletService::balance($provider->user)`; job history `Booking::where('provider_id', $provider->id)->latest()` | **read** (no new logic) |

### 2.1 The three real gaps, stated plainly

1. **`providers.is_online` self-service writer — MISSING.** Needed by screen 2, a
   P1 blocker. Proposed `SetProviderOnlineStatusAction::execute(Provider $p, bool $online, ?float $lat, ?float $lng)`:
   sets `is_online`, stamps `location_updated_at = now()`, and if lat/lng supplied
   (from browser `navigator.geolocation`) sets `current_lat/current_lng`.
   Trivial, but it does not exist today.
2. **Provider location — no update path anywhere.** `current_lat/current_lng` are
   only ever populated by `QaSeeder` (`app/Services/Qa/QaSeeder.php:830,862`).
   `DispatchService::findCandidates()` hard-filters `whereNotNull('current_lat')`
   **and** distance ≤ zone radius (`DispatchService.php:445-446, 78`). A web-only
   provider with null or stale coordinates **is never offered a job.** P1 must at
   minimum capture a one-shot browser geolocation when the provider goes online
   (folded into `SetProviderOnlineStatusAction` above). A live location ping loop
   is P3.
3. **Decline** — see 4b. Low priority; the timeout path already works.

### 2.2 Not a gap, just a note

- **`provider_en_route`** status has **no transition action** — nothing in
  `app/Actions/` writes it (`grep` confirms only cancel/hold/reassign/worker-assign
  *tolerate* it as an input state). Every existing provider flow goes
  `assigned` → (`StartBookingAction`) → `in_progress` → (`CompleteBookingAction`) →
  `completed`. The provider web does the same; there is no "I'm on my way" button
  to build a backend for. If one is wanted later it's a new `MarkEnRouteAction`.
- `StartBookingAction` is **not required** before completion —
  `CompleteBookingAction` accepts `assigned` directly
  (`CompleteBookingAction.php:56`). The start-OTP screen is therefore optional in
  the flow, but should be built (it's the honest "arrived" checkpoint and E5
  hardened it for exactly this).

---

## 3. Real-time vs polling

### 3.1 There is no real-time channel

- `BROADCAST_CONNECTION=log` (`.env.example`). `NewJobOffered` and
  `BookingStatusUpdated` are `ShouldBroadcast`, but with the `log` driver they are
  written to `storage/logs`, delivered nowhere. No Reverb / Pusher / websocket
  server is configured.
- Mobile push is also absent — `ServiceMatchingJob.php:167` carries a
  `TODO: also push via FCM once Firebase credentials are configured`.

**Polling is the only option for a first version, and it is acceptable** — it is
strictly better than the current provider-web experience (which is nothing), and
it matches the constraint the mobile app is under too. It reuses the exact
`wire:poll` pattern already shipped in `customer/orders/show.blade.php:23`.

### 3.2 Does polling fit the dispatch timing?

`ServiceMatchingJob` tuning (all admin-editable via Settings, scope-aware
`Setting::get`, defaults shown):

| Setting | Default | Meaning |
| --- | --- | --- |
| `dispatch.offer_batch_size` | 5 | providers offered simultaneously per round |
| `dispatch.offer_timeout_seconds` | **25** | how long one offer stays `notified` before → `timeout` |
| `dispatch.max_rounds` | 6 | safety cap before the booking is left for manual admin assignment |
| `dispatch.max_timeouts_per_provider` | 3 | per-booking circuit breaker — after 3 lapsed offers this provider is not re-offered *that* booking |

`NewJobOffered::broadcastWith()` also hardcodes `expires_in_seconds: 25`.

**The 25s window is too tight for a backgrounded browser tab.** A 5s poll leaves
~20s to act in the best case, but browsers throttle timers in hidden/inactive
tabs, so a provider whose offers tab is not foregrounded can miss the entire
round. Recommendation:

1. **Raise `dispatch.offer_timeout_seconds` to 45–60** for zones/franchises that
   have web providers. `ServiceMatchingJob` reads it live via `Setting::get` with
   scope — **no code change**, just a Settings row. Also bump the
   `expires_in_seconds` literal in `NewJobOffered` if/when that event is ever
   actually delivered (cosmetic today).
2. **Poll the offers screen at 3–5s** (`wire:poll.4s`), tighter than the
   customer tracker's 6s because the actionable window is short.
3. **Document the operational constraint:** a web provider must keep the offers
   tab open and foregrounded to receive work. True push parity (Reverb or FCM) is
   out of scope for P1/P2 and listed in P3.
4. `max_timeouts_per_provider=3` is fine as-is — a web provider who ignores 3
   offers on one booking dropping out of *that* booking's rotation is correct
   behaviour, not a bug to work around.

---

## 4. Route / URL structure

### 4.1 Existing namespaces (no collision with any of these)

| Surface | Path root | Route-name prefix | Gate |
| --- | --- | --- | --- |
| Customer web | `/` (unprefixed) | `customer.*` | `auth` (`web` guard) |
| Admin panel | `/admin/*` | `admin.*` | `auth` + `EnsureHasAdminAccess` |
| REST API | `/api/*` | (none) | `auth:sanctum` |

`/admin/providers` and `/api/providers/nearby` exist but neither owns the
`/provider` (singular, no trailing segment) path root. The `provider.*` route-name
namespace is entirely unused.

### 4.2 Proposed — add to `routes/web.php` above the `require __DIR__.'/admin.php'` line

```
Route::middleware('guest')->group(function () {
    Route::get('/provider/login',  ProviderLogin::class)->name('provider.login');
});

Route::middleware(['auth', EnsureIsProvider::class])->prefix('provider')->group(function () {
    Route::get('/',                 ProviderDashboard::class)->name('provider.dashboard');   // + online/offline toggle
    Route::get('/jobs',             ProviderJobs::class)->name('provider.jobs.index');        // offers + active, wire:poll
    Route::get('/jobs/{booking}',   ProviderJobShow::class)->name('provider.jobs.show');      // active job + start/completion OTP
    Route::get('/earnings',         ProviderEarnings::class)->name('provider.earnings');      // P2
    Route::get('/history',          ProviderHistory::class)->name('provider.history');        // P2
    Route::get('/profile',          ProviderProfile::class)->name('provider.profile');        // P3
});

Route::post('/provider/logout', /* mirror of customer.logout */)->middleware('auth')->name('provider.logout');
```

- Online toggle: a Livewire action on `ProviderDashboard` (no dedicated POST route
  needed — same as how the customer components work). If a non-Livewire fallback
  is wanted, `POST /provider/online` → `provider.online`.
- `bootstrap/app.php` `redirectGuestsTo`: add
  `$request->is('provider', 'provider/*') ? route('provider.login') : ...`.
- Reuse `components.layouts.customer` or fork a lean `components.layouts.provider`
  (recommended — different nav, no cart/wallet chrome).

---

## 5. Phased build list

### P1 — minimum viable (online toggle + accept + OTP flow)

1. `EnsureIsProvider` middleware (mirror of `EnsureHasAdminAccess`).
2. `ProviderLogin` Livewire component + `GET /provider/login` + `POST /provider/logout`;
   `redirectGuestsTo` branch.
3. **`SetProviderOnlineStatusAction`** (new) — writes `is_online`,
   `location_updated_at`, and `current_lat/current_lng` from a one-shot browser
   geolocation captured on the toggle. *This is the only new business-logic class in P1.*
4. `ProviderDashboard` — greeting, KYC/active status (read `providers.kyc_status`,
   `is_active`), the online/offline toggle, count of open offers.
5. `ProviderJobs` — `wire:poll.4s`; lists `dispatch_attempts` rows with
   `status='notified'` for this provider (with booking/service/address);
   **Accept** → `AcceptBookingAction`. Shows the current active job if one exists.
6. `ProviderJobShow` — customer name + phone + address; **start-OTP** field →
   `StartBookingAction`; once `in_progress`, **completion-OTP** field →
   `CompleteBookingAction`. `provider_id` ownership guard, 404 (not 403) on mismatch.
7. Ops: add a `dispatch.offer_timeout_seconds = 45` Setting for web-provider zones;
   document the "keep the tab foregrounded" constraint.
8. Tests: `tests/Feature/ProviderWeb/*` — login gate, online toggle persists,
   offer appears then Accept assigns, wrong OTP rejected + attempt counted, correct
   OTP starts then completes, full E2E mirroring `CustomerJourneyE6Test`.

### P2 — earnings & history

9. `ProviderEarnings` — `provider_commission` per completed booking from
   `commissions`, wallet balance via `WalletService::balance()`, simple date
   range. Read-only.
10. `ProviderHistory` — past `bookings` for this provider, filterable by status.
11. `DeclineJobOfferAction` (new, tiny) — writes `dispatch_attempts.status='rejected'`;
    already honoured by `DispatchService`. Add a Decline button to `ProviderJobs`.

### P3 — profile, live location, push

12. `ProviderProfile` — skills, zone, KYC documents/status (read-only first;
    edit-request flow later).
13. Live location: periodic `navigator.geolocation` → lightweight endpoint updating
    `current_lat/current_lng/location_updated_at` (throttled).
14. Replace polling with real-time — stand up Laravel Reverb (or wire FCM), deliver
    the already-existing `NewJobOffered` / `BookingStatusUpdated` events; revert
    `dispatch.offer_timeout_seconds` to 25.
15. Google / Firebase-phone buttons on `/provider/login` (copy the handlers from
    `Customer\Auth\Login`).

---

## 6. Deliberately not implemented / out of scope

- Any change to `ServiceMatchingJob`, `DispatchService`, `AcceptBookingAction`,
  `StartBookingAction`, `CompleteBookingAction`, `BookingOtpService` — the provider
  web is a **caller**, not a rebuild.
- FieldWorker (`fieldWorkerProfile`) web — parcel/taxi/delivery riders have their
  own `WorkerJobController` family; this scope is the **Provider** (service
  partner) only.
- Provider onboarding / KYC submission on the web — stays admin-driven
  (`ProviderPreRegister`).
- Real-time transport (Reverb/Pusher/FCM) — P3.
- In-job chat, extra-work proposal UI, tips — existing API-only, not in P1–P3.
- A separate auth guard or `providers` login table — not needed; `web` guard +
  `providerProfile` existence check is sufficient.
