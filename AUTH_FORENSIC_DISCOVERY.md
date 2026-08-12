# Authentication / OTP / QR / Notification — Forensic Discovery

Full repository search, this session. Every claim below is backed by a file path — nothing inferred from memory of prior sessions. **No Glover reference video or documentation was actually accessible in this session** (confirmed: `find` for `*glover*`, `*.mp4`, `*.mov` across the repo returns nothing) — this is stated plainly rather than fabricating what wasn't provided. See `GLOVER_VS_1CALLFIX_AUTH_AUDIT.md` for how that's handled.

## Search results, by term

| Term | Found in | Verdict |
|---|---|---|
| `otp`/`OTP` | `Otp.php` model, `AcceptBookingAction`, `StartBookingAction`, `CompleteBookingAction`, `AdminReassignBookingAction`, `Booking.php`, `Settings/Manage.php`, `WorkerJobController`, `DispatchController`, 3 dev-only `Test*` console commands, `QaSeeder.php` | Two separate, unconnected implementations exist (see below) |
| `firebase`/`FCM` | `User.php` (`fcm_token` column + `routeNotificationForPush()`), `ServiceMatchingJob.php` (a TODO comment only), `PushChannel.php`, `LogPushAdapter.php`, `PushAdapter.php` contract | **No Firebase SDK, no `config/services.php` `firebase` key, no credentials anywhere.** Confirmed by the adapter's own docblock: "no FCM/APNs credentials exist anywhere in this codebase." |
| `Twilio`/`MSG91`/SMS provider | `SmsChannel.php`, `LogSmsAdapter.php`, `SmsAdapter.php` contract | **No SMS gateway configured.** Confirmed by the adapter's own docblock: "no real SMS gateway is configured anywhere in this codebase (confirmed by audit)." |
| `QR`/`qr_code`/`barcode`/`scan`/`pairing` | **nothing** | **Zero QR/scan/pairing code exists anywhere in this repository.** This is a from-scratch design, not an extension of anything partial. |
| `Sanctum`/`token` | `composer.json` (`laravel/sanctum ^4.0`), `personal_access_tokens` migration, `User::HasApiTokens`, `auth:sanctum` middleware on `routes/api.php` | Installed and wired for *authorization* of already-issued tokens. **No code path issues a token** — see Part 4 below. |
| `login`/`authentication`/`auth` | `routes/admin.php` (session-based admin login, `Livewire\Auth\Login`), `EnsureHasAdminAccess` middleware | Admin login exists and works (session-based, pre-existing, not part of this task's scope). **No customer/partner/worker login exists anywhere.** |
| `device`/`device_token` | `users.fcm_token` (single nullable string column) | **No `devices` table.** One push token per user, last-write-wins — cannot represent "this user has 3 registered devices." |
| `password reset`/`magic link`/`recovery` | nothing beyond the OTP failure paths already in `AcceptBookingAction`/`CompleteBookingAction` (wrong-OTP → exception, no lockout) | Not implemented for login (no login exists to recover from); booking-OTP recovery is minimal (see Part 12 equivalent in `OTP_ARCHITECTURE.md`) |

## The two OTP systems that currently coexist, unconnected

**System 1 — `otps` table + `Otp` model.** Schema: `phone, code, purpose (default 'login'), expires_at, verified_at`. **Zero consumers anywhere in `app/`** — confirmed by `grep -rln "Otp::" app/` returning nothing. The `purpose` column's own inline comment (`// login, booking_start, booking_complete`) reveals the *original* design intent was almost certainly a single shared OTP table for both login and booking — that plan was apparently abandoned before any code was written against it.

**System 2 — inline `bookings.start_otp`/`bookings.completion_otp` string columns.** This is the one actually used, exclusively for the Service booking flow (see `AUTH_FORENSIC_DISCOVERY.md`'s Part 2 equivalent, `OTP_ARCHITECTURE.md`, for the full trace). No `attempt_count`, no lockout, no hashing — plain string columns.

**These have never been connected.** The dormant table was never wired to the working booking flow, and no login system was ever built against either.

## Firebase vs. FCM vs. SMS — precisely distinguished (Part 5's explicit ask)

- **Firebase Phone Authentication:** not present in any form. No `firebase/php-jwt`, no `kreait/firebase-php`, no Firebase Admin SDK dependency in `composer.json`.
- **Firebase Cloud Messaging (push notifications):** not present. `PushAdapter`/`PushChannel` exist as a clean abstraction *for* eventually plugging in FCM (or APNs, or any push provider) — the interface is real and used, the FCM implementation behind it is not. Default binding is `LogPushAdapter` (writes to the Laravel log, sends nothing).
- **SMS OTP delivery:** not present. Same shape — `SmsAdapter`/`SmsChannel` are real and used by `BookingStatusNotification`/`PaymentStatusNotification`/etc., default binding is `LogSmsAdapter` (log only).
- **What IS real:** the abstraction layer itself. `ChannelResolver::resolve()` turns an admin-configurable `notifications.channels` Setting (`mail,sms,push,in_app`) into actual Laravel `Notification::via()` channel classes, scope-cascaded the same way every other Setting in this app is. This is genuinely well-built, swap-in-ready infrastructure — just with no real external provider plugged in yet, by design (`AppServiceProvider::register()` binds `SmsAdapter`→`LogSmsAdapter` and `PushAdapter`→`LogPushAdapter` explicitly, with a docblock inviting a future swap).

## Critical finding: the Service booking OTP is never actually delivered to the customer

Traced exhaustively: `AcceptBookingAction` generates `start_otp`/`completion_otp` and saves them on the `Booking` row. `DispatchController::accept()` returns `start_otp` in its JSON response — **to the calling Provider**, not the customer. `BookingStatusNotification` (the only notification sent to the customer on `assigned`) contains generic copy ("A provider has been assigned to your booking X") — **the OTP value itself is never included in any notification, any API response the customer would see, or anywhere in the Admin Panel** (`grep -n "start_otp\|completion_otp" resources/views/livewire/bookings/*.blade.php app/Livewire/Bookings/*.php` returns nothing). There is currently no implemented path for a customer to actually learn their own OTP. This is a real, previously-undocumented gap, not a hypothetical — flagged in detail in `OTP_ARCHITECTURE.md` and `GLOVER_VS_1CALLFIX_AUTH_AUDIT.md`, not silently worked around.

## Routes — the complete current picture

`routes/api.php`, read in full: 24 routes, **zero of which are a login, OTP-request, or OTP-verify endpoint.** Every protected route requires `auth:sanctum` — meaning a Sanctum token must already exist before any of them can be called. Nothing in this codebase currently creates that first token for a Customer, Partner, or Worker. Admin login (session-based, `routes/admin.php`) is real, pre-existing, and out of scope for this task.

## Settings already established for booking OTP (reused, not reinvented, in the design docs)

`booking.otp_length` (default `4`, scope-cascaded, admin-editable via `Settings\Manage` — confirmed at `app/Livewire/Settings/Manage.php:282,425`). No equivalent setting exists yet for a login-OTP length, expiry, or resend cooldown — these would need their own keys if a login OTP is built (see `OTP_ARCHITECTURE.md`).

## Config file evidence (authoritative, not inferred)

`config/services.php`, read in full: `postmark`, `razorpay`, `google_maps`, `resend`, `ses`, `slack`. **No `firebase` key. No SMS gateway key.** This single file is the clearest, most authoritative confirmation of the Firebase/SMS findings above.
