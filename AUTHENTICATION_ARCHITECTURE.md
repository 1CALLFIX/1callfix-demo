# Authentication Architecture

## Target architecture (Part 14) — implemented as diagrammed in the mission brief

```
                 1CALLFIX IDENTITY
                         │
          ┌──────────────┼──────────────┐
          │              │              │
         OTP           QR LOGIN      EXISTING SESSION
   (AuthController)  (QrAuthController)  (Sanctum token
          │              │               already held)
          └──────────────┼──────────────┘
                         │
                    Sanctum (personal_access_tokens)
                         │
          ┌──────────────┼──────────────┐
          │              │              │
      Customer        Partner         Worker
   (self-registers   (must pre-exist, (must pre-exist,
    on first login)   providerProfile) fieldWorkerProfile)
```

**One shared implementation, not three.** `AuthController`/`OtpService`/`Otp` model are used identically regardless of `actor_type` — the only actor-specific logic is a single `match` in `resolveExistingActor()`/`resolveCustomer()` deciding which profile relationship to check. No separate Customer-auth, Partner-auth, or Worker-auth system exists or was created — exactly what Part 14 requires ("Do not create separate authentication implementations").

## Per-actor readiness (Part 4/15)

| | Customer | Partner (Provider) | Worker (Field Worker) | Admin |
|---|---|---|---|---|
| Route | `POST /api/auth/otp/request` + `/verify` | same | same | `routes/admin.php` (pre-existing, session-based, untouched) |
| Account model | Self-registers on first verified login | Must already exist with `providerProfile` (KYC-gated onboarding, pre-existing) | Must already exist with `fieldWorkerProfile` (KYC-gated onboarding, pre-existing) | Pre-existing |
| OTP generation | `OtpService::generate()` | same | same | N/A |
| OTP delivery | SMS via `SmsAdapter` (Log-bound in this environment — see `NOTIFICATION_ARCHITECTURE.md`) | same | same | N/A |
| Verification | `OtpService::verify()` — hashed, attempt-limited, lockout | same | same | N/A |
| Token/session | Sanctum `createToken()`, `POST /api/auth/logout` revokes it | same | same | Laravel session |
| Device registration | `POST /api/auth/device` (single `fcm_token` column — see limitation below) | same | same | N/A |
| QR login | `POST /api/auth/qr/*` — same shared flow | same | same | Not built (kiosk/desktop use case not established for admin) |
| Rate limiting | `throttle:5,1` on request, `throttle:10,1` on verify | same | same | Not audited this session (pre-existing) |
| Error handling | 422/404/410/429 distinguished, no stack traces | same | same | Pre-existing |

**All of the above is IMPLEMENTED and tested this session (25 tests) — not partial, not aspirational.**

## Known limitation, stated plainly: single device per user

`users.fcm_token` is one nullable string column (`AUTH_FORENSIC_DISCOVERY.md`) — no `devices` table exists. Registering a second device silently overwrites the first device's push token. This is not fixed in this session: building real multi-device support is a genuine schema expansion (a `devices` table, `user_id`+`device_identifier`+`fcm_token`+`last_seen_at`, with its own migration and consumer changes across `routeNotificationForPush()`/`PushChannel`) that goes beyond "the authentication foundation" scope and risks exactly the kind of uncontrolled expansion the mission explicitly warns against. **Recommended as the next concrete follow-up**, not silently left unmentioned.

## Security posture (Part 21, summarized — full detail in the PR/commit history)

- **OTP brute force:** mitigated (attempt lockout, tested).
- **OTP enumeration:** mitigated (identical `/otp/request` response regardless of account existence, tested at the HTTP level).
- **OTP replay:** mitigated (status flips to `verified` on success, a second submission of the same code fails, tested).
- **OTP leakage:** the plaintext code exists only transiently inside `OtpService::generate()`'s local scope — never persisted, never returned in any API response, never logged directly by this session's new code (the existing `LogSmsAdapter` does log the full SMS body including the code, by design, as the safe QA-mode stand-in for a real provider — see `NOTIFICATION_ARCHITECTURE.md`'s explicit pre-production requirement about this).
- **QR replay/screenshot reuse:** mitigated (one-time confirm, tested).
- **QR credential theft via the displayed image:** mitigated by the two-token split (`QR_SCAN_ARCHITECTURE.md`).
- **Session/token theft:** Sanctum tokens are bearer tokens over HTTPS (production TLS is an infrastructure concern outside this codebase's scope) — no new exposure introduced.
- **Device token theft:** not newly introduced; the pre-existing single-`fcm_token`-column limitation is unchanged, documented above.
- **Cross-user verification / cross-actor-type login:** `resolveExistingActor()` requires the SPECIFIC profile relationship (`providerProfile`/`fieldWorkerProfile`) to exist — a verified phone with no matching profile is rejected (404), tested directly.

**No CRITICAL or unresolved HIGH finding from this session's new code.** See `PRODUCTION_READINESS_AUDIT.md` for the cumulative security record across all sessions, including the one HIGH finding from the previous session (`Franchises\Manage`, already fixed).

## What's explicitly NOT built (Part 25's boundary, respected)

No mobile UI. No password-based auth (OTP-only, matching the mission's framing throughout — a password field was never part of this design). No email-based login. No multi-device support (see above). No admin-facing QR login (kiosk use case not established). No job-verification or identity QR (`QR_SCAN_ARCHITECTURE.md`). No Parcel/Taxi/Food OTP business logic.
