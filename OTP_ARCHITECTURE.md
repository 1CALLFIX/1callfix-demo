# OTP Architecture

## Part 3's question: Option A, B, or C?

**Option A** — booking-specific OTP fields only (status quo, no shared engine). **Option B** — migrate everything, including the working Service booking OTP, onto a shared table/service. **Option C** — hybrid: a shared engine for LOGIN/verification OTP, while the Service booking OTP stays exactly where it is.

**Recommendation: Option C, implemented this session.** Reasoning:

- The Service booking OTP (`bookings.start_otp`/`completion_otp`) is deeply embedded in tested, transactional, FSM-integrated Actions (`AcceptBookingAction`, `StartBookingAction`, `CompleteBookingAction`) — 16+ passing tests depend on its exact current shape, and the mission's own absolute rules forbid redesigning it without a proven defect. None was found (see below). Migrating it onto a shared table would be a large, risky rewrite of working code for no functional gain.
- Login OTP is a genuinely new capability with **zero existing implementation** to preserve (`AUTH_FORENSIC_DISCOVERY.md`). Building it on a clean, purpose-built engine is the correct, safe, additive path — nothing to break.
- The dormant `otps` table's own `purpose` column comment (`// login, booking_start, booking_complete`) suggests a shared-table design was the *original* intent — but it was never built, and retrofitting it under the working booking flow now would be exactly the kind of unjustified rewrite the mission prohibits. Repurposing the same dormant table for its apparently-originally-intended LOGIN use only (not booking use) honors that original intent without touching anything working.

## The shared engine (`Otp` model / `OtpService`)

Extended this session (migration `2026_08_13_001000`, additive, zero data at risk — confirmed zero consumers before touching it):

| Column | Purpose |
|---|---|
| `phone` | Recipient identifier (no `User` row necessarily exists yet) |
| `code_hash` | `Hash::make()`'d — never plaintext at rest (renamed from the original `code` column) |
| `purpose` | `'login'` today; extensible string, not a DB enum (matches `service_categories.module`'s established reasoning — a new purpose is a routine addition, not a migration) |
| `channel` | Which channel actually delivered it (`'sms'` today) |
| `attempt_count` / `max_attempts` | Lockout tracking, default max 5 |
| `status` | `pending` / `verified` / `expired` / `locked` |
| `last_sent_at` | Resend-cooldown enforcement |
| `ip_address` / `device_identifier` | Audit trail |
| `expires_at` / `verified_at` | Existing columns, unchanged meaning |

**Security properties, all real and tested (`AuthOtpTest`, 15 tests):** one active code per `(phone, purpose)` at a time (a fresh request invalidates any still-pending prior code); hashed storage; attempt-limited with a hard lock after `max_attempts`; resend-cooldown (`auth.otp_resend_cooldown_seconds`, default 30s); every generate/verify call is auditable via the row itself.

## Existing Service booking OTP — traced exactly, left untouched

Generated in `AcceptBookingAction::execute()` (start_otp + completion_otp both generated at acceptance) and `AdminReassignBookingAction` (regenerated only if not already set). Length: `Setting::get('booking.otp_length', 4)`, admin-editable, scope-cascaded. Verified in `StartBookingAction`/`CompleteBookingAction` via plain string comparison (`$booking->start_otp !== $enteredOtp`) — **not hashed**, a real difference from the new login engine's hashing, and a deliberate one: changing this now would be exactly the kind of Service-flow redesign the mission prohibits without a proven defect, and none was found (plain comparison against a random 4-digit code, itself only ever exposed transiently in an authenticated API response to the assigned Provider, is not a demonstrated vulnerability — see `AUTHENTICATION_ARCHITECTURE.md`'s security section for the one real gap that WAS found, which is delivery, not comparison method).

**No attempt tracking, no lockout on the booking OTP** — a wrong `start_otp`/`completion_otp` throws a `RuntimeException` ("Incorrect start OTP"/"Incorrect completion OTP") and the caller can simply retry immediately, indefinitely. This was true before this session and remains true — not changed, per the mission's own explicit instruction ("Wrong OTP → show invalid OTP → allow retry. Do NOT cancel booking") describing exactly this existing behavior as correct, not a defect to fix.

**The one real, previously-undocumented gap found this session:** the booking OTP is never actually delivered to the customer (see `AUTH_FORENSIC_DISCOVERY.md`'s critical finding). This is a delivery gap, not a verification-logic gap — fixing it doesn't require touching `AcceptBookingAction`/`StartBookingAction`/`CompleteBookingAction` at all, only adding a notification send at the point `start_otp`/`completion_otp` are generated. **Not fixed in this session** (see `PROJECT_CURRENT_STATE.md`'s remaining-work section) — flagged for the record rather than silently patched into financially/operationally sensitive Actions without more deliberate review than this session's time budget allows.

## OTP failure recovery — Part 12

| Scenario | Login OTP (new) | Service booking OTP (existing, unchanged) |
|---|---|---|
| Wrong code | Rejected (422), retry allowed, attempt counted | Rejected (`RuntimeException`), retry allowed, **not** counted |
| Expired | Rejected (410), must request a new one | No expiry mechanism exists on the booking OTP today — **NOT IMPLEMENTED**, carried forward as a known gap, not invented as a fix without approval |
| Not received / resend | New request allowed after cooldown (429 if too soon) | No resend mechanism — regeneration only happens via `AdminReassignBookingAction`, an admin action, not a customer-facing resend |
| Repeated failures | Locked after `max_attempts` (default 5), must request a new code | No lockout — unchanged, matches the mission's own "do not cancel booking" instruction |
| Customer refuses/unavailable | N/A (login is self-service) | No automatic bypass exists — `PlaceBookingOnHoldAction`/`AdminCancelBookingAction` are the only existing controlled paths, both admin/operator-initiated, both already audited/tested. **No invisible OTP bypass was found or created.** |

## Extension mechanism for future verticals — Part 20

Neither engine needs to change shape for Parcel pickup/delivery OTP, Taxi verification, or Food delivery verification: the login engine's `purpose` column already accepts any string (a `'parcel_pickup'` purpose is a value, not a migration), and the booking-OTP pattern (`start_otp`/`completion_otp`-shaped columns on the relevant order table, generated/verified inside that vertical's own Actions) is a proven, reusable shape — not vertical-specific logic. **Not implemented — Parcel/Taxi/Food business logic remains explicitly out of scope for this session**, per the mission's own absolute rules.
