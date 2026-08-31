# Auth Rebuild — Completion Report

**Branch:** `feature/auth-password-rebuild` (cut from `feature/customer-web-foundation` @ `a602671`)
**Scope:** password-first login; OTP demoted to verification only (signup / password reset /
Google linking). No merge, no deploy, no migration run against `api.1callfix.com`.

---

## 1. Decisions taken this session (recorded, because the prompt's stack assumptions did not match the repo)

| Question | Answer used |
|---|---|
| The repo has **no Firebase phone auth** — it had a server-side `OtpService` (SMS via a log-bound adapter). Build on which stack? | **Introduce Firebase now** (JS SDK client-side + server-side ID-token verification). |
| Identifier model — `users.phone` is `NOT NULL UNIQUE`, `users.email` is `nullable UNIQUE`, `password` column already existed (nullable, unused), **no `email_verified_at`**. | **Mobile mandatory, email secondary.** No account can exist without a verified phone. |
| API surface — web only, or the REST API too? | **API too**, as a *controlled migration* (audit consumers → build + test replacements → demote, don't delete). |
| Server-side Firebase ID-token verification library. | **`firebase/php-jwt` v7.1 + a JWKS wrapper.** `kreait/firebase-php` (the first choice) **is not installable in this environment**: `ext-sodium` is not enabled, and its sodium-free versions require a security-advisory-blocked `firebase/php-jwt`. `openssl` is present, so RS256 verification is done directly. |
| Fate of the old `/api/auth/otp/*` login contract. | **Unified / replace, as a migration.** Endpoints kept but demoted to email `email_verify` / `password_reset` codes; they never issue a login token. Removal is gated on the mobile client shipping the Firebase flow — **not done here**. |

## 2. Task 2 schema question — explicit answer

`users` before this branch: `phone` `string NOT NULL UNIQUE` (the primary identifier), `email`
`string nullable UNIQUE` (secondary, optional), `password` `string nullable` (present since P1,
never populated), `phone_verified_at` present, **`email_verified_at` absent**, no social columns.

**Built against: mobile-required.** Every account still requires a Firebase-verified `phone`
(`users.phone` unchanged — still `NOT NULL UNIQUE`). Email is an optional, separately-verifiable
(custom email OTP) **secondary login identifier** — you can sign in with it, but you cannot create
an account with only an email. "Signup via email" therefore means *"signup (which requires a
mobile) that also verifies and attaches an email"*, not an email-only account.

Additive migration `2026_08_29_001000_add_password_auth_columns_to_users.php`:
`email_verified_at` (timestamp, nullable), `firebase_uid` (string, nullable, unique),
`google_id` (string, nullable, unique), `avatar_url` (string, nullable). Every column nullable,
no backfill.

Migration `2026_08_29_002000_rename_otps_phone_to_identifier.php`: `otps.phone` → `otps.identifier`
(the engine is email-only now); index renamed to match.

**Password baseline built:** minimum 8 characters, hashed with `Hash::make` (Laravel default —
bcrypt), never logged, never returned in any response (`User::$hidden` already lists `password`).
No complexity / breach-list rule — flag: `Password::defaults()` or a `zxcvbn` check is a reasonable
follow-up if you want it stricter.

## 3. The "enter your code" recurring login screen

**Removed, not just unrouted.** `app/Livewire/Customer/Auth/Login.php` was rewritten from an
OTP state machine (`step` = `phone` | `code`, `requestCode()` / `verifyCode()` / `resendCode()`)
to identifier + password (`login()`); `resources/views/livewire/customer/auth/login.blade.php`
was rewritten with no code step. `App\Notifications\OtpNotification` (the SMS login-code copy) is
**deleted**. The `otps.purpose = 'login'` value is retired (the demoted API endpoints reject it).

## 4. Deployment-state confirmation

Nothing here touched the E8 deployment state, the `main` branch, or `api.1callfix.com`. All work
is on `feature/auth-password-rebuild`. No `php artisan migrate` was run against any non-test
database. `npm run build` was run locally only to add `resources/js/customer-auth.js` to the Vite
manifest (`public/build/` is gitignored — not committed). Two new composer deps
(`firebase/php-jwt`) and one npm dep (`firebase`) are in `composer.json` / `package.json`.

## 5. Files touched, grouped by task

### Dependencies / config
- `composer.json`, `composer.lock` — add `firebase/php-jwt ^7.1`
- `package.json`, `package-lock.json` — add `firebase`
- `vite.config.js` — add `resources/js/customer-auth.js` input
- `config/services.php` — new `firebase` block; `sms.country_code`
- `.env.example` — `FIREBASE_*` / `VITE_FIREBASE_*` / `SMS_COUNTRY_CODE` keys, documented

### Task 0 — consumer audit
- `docs/auth-otp-consumer-audit.md` (new)

### Task 1 — OTP infrastructure
- `app/Contracts/FirebaseTokenVerifier.php` (new) — interface
- `app/Services/Auth/FirebaseIdentity.php` (new) — verified-claims DTO
- `app/Services/Auth/GoogleFirebaseTokenVerifier.php` (new) — RS256 verification vs Google certs
- `app/Exceptions/FirebaseAuthException.php` (new)
- `app/Providers/AppServiceProvider.php` — bind the verifier
- `app/Services/OtpService.php` — repurposed to email-only (`$phone` → `$identifier`, `SmsAdapter`
  dropped, delivers `EmailOtpNotification`; hashing / lockout / cooldown unchanged); `PURPOSE_*` consts
- `app/Models/Otp.php` — `fillable` `phone` → `identifier`; docblock
- `app/Notifications/EmailOtpNotification.php` (new)
- `app/Notifications/OtpNotification.php` (**deleted** — dead)
- `app/Support/PhoneNumber.php` (new) — national / E.164 reconciliation
- `database/migrations/2026_08_29_002000_rename_otps_phone_to_identifier.php` (new)
- `resources/js/customer-auth.js` (new) — Firebase JS SDK glue (phone OTP + Google popup)
- `resources/views/components/layouts/customer.blade.php` — `@stack('scripts')`

### Task 2 — signup
- `database/migrations/2026_08_29_001000_add_password_auth_columns_to_users.php` (new)
- `app/Models/User.php` — `fillable` + `email_verified_at` cast for the new columns
- `app/Services/Auth/CustomerAccountResolver.php` — rewritten as the shared provisioning authority
  (`findByLoginIdentifier`, `findByPhone/Email/FirebaseUid`, `findForFirebaseIdentity`,
  `completeSignup` [resume-or-create], `createFromGoogle`, `linkFirebaseIdentity`, `setPassword`,
  `markPhoneVerified`, `phoneMatches`, `isEmail`)
- `app/Exceptions/AccountAlreadyExistsException.php` (new)
- `app/Livewire/Customer/Auth/Signup.php` (new) + `.../signup.blade.php` (new)
- `app/Livewire/Customer/Auth/Concerns/InteractsWithAuthThrottle.php` (new) — shared per-identifier/IP limiter
- `routes/web.php` — `/signup`

### Task 3 — login
- `app/Livewire/Customer/Auth/Login.php` — rewritten (identifier + password; Google entry;
  password-less → migration redirect; mandatory throttle)
- `resources/views/livewire/customer/auth/login.blade.php` — rewritten
- `resources/views/livewire/customer/auth/_alerts.blade.php` (new)
- `app/Http/Controllers/API/AuthController.php` — `POST /api/auth/password` (throttled)
- `routes/api.php` — `/auth/password`

### Task 4 — forgot password
- `app/Livewire/Customer/Auth/ForgotPassword.php` (new) + `.../forgot-password.blade.php` (new)
- `resources/views/livewire/customer/auth/_firebase-phone.blade.php` (new) — shared phone-OTP sub-widget
- `AuthController` — `forgotPassword()` / `resetPassword()`
- `routes/web.php` — `/forgot-password`; `routes/api.php` — `/auth/password/{forgot,reset}`

### Task 5 — Google sign-in + mandatory mobile
- `app/Livewire/Customer/Auth/GoogleAuth.php` (new) + `.../google-auth.blade.php` (new)
- `Login::continueWithGoogle()` (web) + `AuthController::firebase()` Google branches (API)
- `CustomerAccountResolver::createFromGoogle()` / `linkFirebaseIdentity()`
- `routes/web.php` — `/auth/google`

### Task 6 — migration path
- `app/Livewire/Customer/Auth/PasswordMigration.php` (new) + `.../password-migration.blade.php` (new)
- `Login::login()` routes a password-less account here; `AuthController::password()` returns
  `409 needs_password_setup`; `AuthController::firebase()` resumes a password-less shell
- `routes/web.php` — `/auth/set-password`

### API migration
- `app/Http/Controllers/API/AuthController.php` — full rewrite: `password`, `firebase`, `register`,
  `forgotPassword`, `resetPassword` added; `requestOtp` / `verifyOtp` demoted (email purposes only,
  never a token); `logout` / `registerDevice` unchanged
- `routes/api.php` — new routes; old `/auth/otp/*` kept + demoted

### Docs
- `AUTHENTICATION_ARCHITECTURE.md`, `OTP_ARCHITECTURE.md` — "auth rebuild" preamble
- `PHASE_AUTH_REBUILD_REPORT.md` (this file)

### Tests (new)
- `tests/Support/FakeFirebaseTokenVerifier.php`
- `tests/Feature/Support/RebuiltAuthHelpers.php`
- `tests/Feature/PhoneNumberTest.php`
- `tests/Feature/Auth/EmailOtpTest.php`
- `tests/Feature/Auth/FirebaseTokenVerificationTest.php`
- `tests/Feature/CustomerWeb/CustomerSignupTest.php`
- `tests/Feature/CustomerWeb/CustomerForgotPasswordTest.php`
- `tests/Feature/CustomerWeb/CustomerPasswordMigrationTest.php`
- `tests/Feature/CustomerWeb/CustomerGoogleAuthTest.php`
- `tests/Feature/Api/FirebaseAuthApiTest.php`
- `tests/Feature/Api/PasswordAuthApiTest.php`

### Tests (rewritten / updated for the new contract)
- `tests/Feature/CustomerWeb/CustomerAuthTest.php` — password login + migration routing + throttle
- `tests/Feature/Auth/AuthOtpTest.php` — the demoted email-verification-only HTTP contract
- `tests/Feature/CustomerWeb/AuthPathEquivalenceTest.php` — web-session vs API-token equivalence
  for the password + Firebase flows through the shared resolver
- `tests/Feature/Onboarding/CustomerPreRegisterTest.php` — the "claim the pre-registered shell"
  test now uses `POST /api/auth/firebase` (phone token + password) instead of the retired OTP login

## 6. Not live-verifiable in this environment (needs your Firebase project + SMTP creds)

Automated tests use `FakeFirebaseTokenVerifier` (no network) and `Notification::fake()`. Before
this is exercised against real infrastructure you must, per `.env.example`:
`FIREBASE_PROJECT_ID` + `FIREBASE_WEB_*` (public web config), the project on the **Blaze** plan,
the deployed host added under Firebase Auth → Authorized domains, and Google enabled as a sign-in
provider. `npm run build` must run wherever the app is served (the manifest is gitignored).

Manual live checklist: real phone receives a Firebase SMS and signup completes; real inbox
receives the 6-digit email code over `mail.1CallFix.com:587`; Google popup → new account → phone
step → active; Google email matching an existing account is blocked until its phone is verified.

## 7. Full raw test suite output

`php artisan test` in this repo emits a single machine-readable summary line (its configured
reporter), not a per-test list. Raw output of the full run:

```
{"tool":"phpunit","result":"passed","tests":1963,"passed":1963,"assertions":5596,"duration_ms":280477}
```

**1963 passed / 1963, 5596 assertions, 0 failures, 0 errors, ~280s.**
(Pre-branch baseline was ~1908; +55 net after rewrites. The one intermediate failure during
development — `PhoneNumberTest` using the removed `@dataProvider` docblock instead of PHPUnit 12's
`#[DataProvider]` attribute — was fixed before this run.)

### `--testdox` for the auth-rebuild tests (92 of the new/rewritten cases; +7 in `CustomerPreRegisterTest`)

```
Auth Otp (Tests\Feature\Auth\AuthOtp)
 ✔ Request then verify an email code returns verified true and no token
 ✔ A wrong code is 422 with no token
 ✔ Purpose is required and restricted
 ✔ A non email identifier is rejected
 ✔ A legacy phone login shaped call is rejected
 ✔ Request is enumeration safe
 ✔ Request is throttled
 ✔ A verified code cannot be replayed
Auth Path Equivalence (Tests\Feature\CustomerWeb\AuthPathEquivalence)
 ✔ A customer registered via the api is the same row the web login resolves
 ✔ A customer who signed up on the web authenticates identically via the api
 ✔ A firebase phone login lands on the same row as the password login
 ✔ Both surfaces provision through the one shared resolver
Customer Auth (Tests\Feature\CustomerWeb\CustomerAuth)
 ✔ Login screen renders for a guest
 ✔ Login screen redirects an already authenticated customer
 ✔ A customer signs in with mobile and password
 ✔ A customer signs in with email and password
 ✔ A wrong password is rejected and does not authenticate
 ✔ An unknown identifier is rejected generically
 ✔ An account with no password is routed to the migration flow
 ✔ Repeated failures are throttled per identifier
 ✔ Logout ends the session
 ✔ Logout is not reachable by get
 ✔ Google verified token for a linked account signs in
 ✔ Google verified token for a new identity hands off to the mobile step
Customer Forgot Password (Tests\Feature\CustomerWeb\CustomerForgotPassword)
 ✔ Reset via email replaces the password
 ✔ Reset via mobile replaces the password
 ✔ A wrong email code is rejected
 ✔ An expired email code is rejected
 ✔ A mobile token that does not match is rejected
 ✔ Reset is rate limited per identifier
 ✔ Email reset does not disclose whether the account exists
Customer Google Auth (Tests\Feature\CustomerWeb\CustomerGoogleAuth)
 ✔ New google user completes signup after verifying a mobile
 ✔ Google email matching an existing account requires that accounts mobile before linking
 ✔ An abandoned verification leaves no account
 ✔ A google token that is not a google provider is rejected at login
 ✔ A rejected google token shows an error
Customer Password Migration (Tests\Feature\CustomerWeb\CustomerPasswordMigration)
 ✔ Login with a passwordless account redirects into the migration flow
 ✔ The full migration path sets a password and signs in
 ✔ A mismatched phone token does not advance the flow
 ✔ An account that already has a password is bounced to login
Customer Signup (Tests\Feature\CustomerWeb\CustomerSignup)
 ✔ Signup via mobile sets a password and logs in
 ✔ Signup can also verify and attach an email
 ✔ A supplied email that is not verified blocks completion
 ✔ Completion is blocked until the mobile is verified
 ✔ A weak password is rejected
 ✔ A fully registered mobile is refused not overwritten
 ✔ An incomplete passwordless account is resumed not duplicated
Email Otp (Tests\Feature\Auth\EmailOtp)
 ✔ Send then verify succeeds end to end
 ✔ The code is stored hashed never in plaintext
 ✔ An expired code is rejected
 ✔ A wrong code is rejected and counts an attempt
 ✔ Repeated wrong codes lock the otp
 ✔ Resend within the cooldown is refused
 ✔ A fresh request after cooldown invalidates the previous code
 ✔ Purposes are isolated
Firebase Auth Api (Tests\Feature\Api\FirebaseAuthApi)
 ✔ Phone token for an existing customer returns a token
 ✔ Phone token for an unknown number asks for registration details
 ✔ Phone token with name and password registers a customer
 ✔ Register endpoint creates a customer from a verified phone
 ✔ An invalid firebase token is a validation error
 ✔ Provider actor type requires a pre existing profile
 ✔ Google token for a new identity asks for a phone
 ✔ Google plus phone creates the account
 ✔ Google email matching an existing account needs a phone link
Firebase Token Verification (Tests\Feature\Auth\FirebaseTokenVerification)
 ✔ A valid phone token yields the identity
 ✔ A google token is recognised
 ✔ A token for a different project is rejected
 ✔ A token with the wrong issuer is rejected
 ✔ An expired token is rejected
 ✔ A tampered payload breaks signature verification
 ✔ An empty token is rejected
 ✔ A token with no subject is rejected
 ✔ A missing project id configuration is reported
Password Auth Api (Tests\Feature\Api\PasswordAuthApi)
 ✔ Correct password returns a token
 ✔ Wrong password is 401 with no token
 ✔ A passwordless account is routed to setup not logged in
 ✔ Password login is throttled per identifier
 ✔ Password reset by email invalidates the old password and tokens
 ✔ Password reset by mobile uses a firebase token
Phone Number (Tests\Feature\PhoneNumber)
 ✔ National collapses every shape to bare ten digits (9 data sets)
 ✔ National is idempotent
 ✔ E164 round trips
 ✔ Looks valid requires ten national digits
 ✔ The country code is configurable

OK (92 tests, 322 assertions)
```

`CustomerPreRegisterTest` (updated, 7 tests incl. `completing real verification claims the
pre-registered shell not a duplicate`) also passes in the full run.
