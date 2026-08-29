# Login-OTP consumer audit — auth rebuild (branch `feature/auth-password-rebuild`)

Prepared before any endpoint change, per the migration requirement: *"audit every current
mobile/API consumer first, identify exactly what the existing OTP endpoints are used for, then
migrate those use cases to the new authentication contract."*

## Scope note — two unrelated OTP mechanisms

| Mechanism | Storage | Purpose | Touched by this branch? |
|---|---|---|---|
| **Login / verification OTP** | `otps` table, `App\Services\OtpService`, `App\Models\Otp` | phone login code (`purpose=login`) | **YES** — repurposed |
| **Booking start/completion OTP** | `bookings.start_otp` / `completion_otp`, `App\Services\BookingOtpService` | provider proves arrival/completion on a job | **NO** — explicitly out of scope, untouched |

Everything below concerns the **login/verification** mechanism only.

## Current contract (pre-migration)

```
POST /api/auth/otp/request   { phone, actor_type }              -> 200 generic message   (throttle:5,1)
POST /api/auth/otp/verify    { phone, actor_type, code }        -> 200 { token, actor_type, user }   (throttle:10,1)
                                                                    OR 4xx { message }
```

`actor_type ∈ {customer, provider, field_worker}`. `customer` self-registers on first verified
code; `provider`/`field_worker` must already have the matching KYC profile or verify returns 404.
On success `verifyOtp` mints a **Sanctum token** — i.e. OTP *is* the login mechanism today.

Web equivalent: `App\Livewire\Customer\Auth\Login` runs the same `OtpService` on the `web`
session guard (no token; `Auth::guard('web')->login()`).

## Consumers

### Application code

| File | Uses | Post-migration disposition |
|---|---|---|
| `routes/api.php` (auth group) | declares `/auth/otp/request`, `/auth/otp/verify` | Routes **kept**, `purpose` restricted to `email_verify` / `password_reset`; `verify` never mints a token. New: `/auth/firebase`, `/auth/password`, `/auth/register`, `/auth/password/forgot`, `/auth/password/reset`. |
| `app/Http/Controllers/API/AuthController.php` | `requestOtp()`, `verifyOtp()` | `requestOtp` → email-OTP send only (phone codes now come from Firebase client-side). `verifyOtp` → returns a short-lived verification result, **no token**. New action methods added for the flows above. `logout()`, `registerDevice()` unchanged. |
| `app/Services/OtpService.php` | the engine | Becomes **email-only**: `SmsAdapter` dependency dropped, delivers via `EmailOtpNotification` over SMTP. Hashing / single-active-code / attempt-lockout / resend-cooldown internals unchanged. `$phone` param → `$identifier`. |
| `app/Models/Otp.php` | `fillable` incl. `phone` | `phone` → `identifier` in `fillable`; docblock updated. |
| `app/Services/Auth/CustomerAccountResolver.php` | phone-only first-or-create | Gains `resolveForLogin(identifier)` (phone OR email), `resolveByFirebaseIdentity(...)`, `createFromVerifiedSignup(...)`. Existing `resolve(phone)` retained for internal reuse. Still the single shared provisioning point for web + API. |
| `app/Livewire/Customer/Auth/Login.php` (+ blade) | `OtpService`, `CustomerAccountResolver` | **Rewritten**: identifier + password. The recurring "enter your code" step is deleted (component logic + blade branch removed, not merely unrouted). |

### Tests (updated in this branch)

| File | What it asserts today | Change |
|---|---|---|
| `tests/Feature/Auth/AuthOtpTest.php` | full OTP-login contract incl. token issuance | Reworked to the **verification-only** contract (send + verify email codes, expiry, lockout, cooldown, enumeration-safe response). Token-issuance assertions move to the new Firebase/password API tests. |
| `tests/Feature/CustomerWeb/CustomerAuthTest.php` | web OTP-login | **Rewritten** for password login + the migration path. |
| `tests/Feature/CustomerWeb/AuthPathEquivalenceTest.php` | web-session and API-token paths resolve the identical `users` row | Updated: same guarantee proven for the **Firebase** and **password** flows via `CustomerAccountResolver`. |
| `tests/Feature/Api/ApiRateLimitTest.php` | throttle on `/auth/otp/*` | Updated to cover throttles on the new `/auth/password` and `/auth/firebase` routes. |
| `tests/Feature/Onboarding/CustomerPreRegisterTest.php` | a pre-registered customer can then log in via OTP | Updated: pre-registered customer logs in via the new **password** path (after a one-time verify-to-set-password), or via **Firebase** phone token. Pre-registration import itself is unchanged. |

### Known external / non-repo consumer

The **mobile client** calls `POST /api/auth/otp/{request,verify}` for login. It is not in this
repository. Post-migration it must obtain a Firebase ID token itself and call
`POST /api/auth/firebase`. The old endpoints are therefore **retained but demoted** (verification /
reset only, never a login token) for a compatibility window; their eventual removal is a separate
step, gated on the mobile client shipping the Firebase flow — **not done in this branch**.

## Policy applied

- OTP removed **as a login mechanism** on every surface (web + API).
- OTP **retained** for identity verification (signup) and password reset — email via this engine,
  phone via Firebase.
- Backwards compatibility for the old endpoints kept only where it does not re-enable OTP login.
- No change to registration/account-verification behaviour beyond swapping the proof-of-ownership
  step: a verified phone (Firebase) or verified email (this engine) still provisions exactly one
  `users` row through `CustomerAccountResolver`.
