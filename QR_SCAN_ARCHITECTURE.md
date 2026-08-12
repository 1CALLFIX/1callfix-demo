# QR / Scan Architecture

No QR/scan/pairing code existed anywhere in this codebase before this session (`AUTH_FORENSIC_DISCOVERY.md`) — this is a from-scratch design, and no Glover reference material was actually accessible to inform it (`GLOVER_VS_1CALLFIX_AUTH_AUDIT.md`). Every decision below follows from general, well-established challenge-response security practice and this codebase's own existing conventions (row-locking for one-winner decisions, `Setting`-driven configurability), not from copying anything.

## Part 7's instruction: separate QR purposes, don't assume one QR does everything

Three distinct concepts, deliberately kept separate:

1. **AUTHENTICATION QR (device pairing)** — "log this OTHER device in using my already-authenticated phone." **Implemented this session.**
2. **JOB VERIFICATION QR** (service start/completion, future Parcel pickup/delivery) — an alternative/supplementary proof-of-presence mechanism for an operational event, NOT a login mechanism. **Designed, not implemented this session** — the existing OTP-based job verification (Service start/completion) is real, working, and tested; QR is a genuine future enhancement to it, not a gap blocking anything today.
3. **IDENTITY QR** (a Customer/Partner/Worker's own scannable identity) — **designed, not implemented.** See below.

These are never the same token, never the same endpoint, and the `qr_challenges.purpose` column exists specifically so a future job-verification or identity-confirmation challenge is a new `purpose` value, not a new table or a redesign of the pairing flow.

## Part 8 — QR security requirements, and how each is met

| Requirement | How it's met |
|---|---|
| Never a password/permanent token/raw secret in the QR | The QR encodes only `qr_token` — 48 bytes of randomness, meaningless on its own, resolves to nothing without a live, unexpired, still-pending `qr_challenges` row |
| Expiry | `auth.qr_challenge_expiry_seconds`, default 120s (`Setting`-driven, admin-configurable, matching every other timing knob in this app) |
| One-time use | `confirm()` flips `pending`→`confirmed` exactly once; a second confirm attempt against the same token (even by a different user) is rejected — **tested directly**, including the exact "first confirmer wins a race" case |
| Purpose-bound | `confirm()` explicitly checks `purpose` matches what the caller expects; a device-pairing QR cannot be confirmed against a job-verification confirm call even if one existed |
| Actor/device/timestamp recorded | `confirmed_by_user_id`, `confirmed_device_identifier`, `confirmed_ip`, `confirmed_at` all captured at confirm time |
| Revocable | `POST /api/auth/qr/revoke` — the initiating side can cancel its own still-pending challenge |
| Replay protection | A screenshot of an old (already-confirmed or expired) QR cannot be reused — **tested directly**, both the already-used and the expired case |
| Rate limiting | `throttle` middleware on every QR endpoint (create: 10/min, status: 60/min, claim: 20/min, confirm: 20/min, revoke: 20/min) |
| Scope | `payload` (JSON) exists for a future purpose-specific scope (e.g. "this challenge is only valid for booking #X") — unused by device-pairing, ready for job-verification |
| Auditability | The full row (who confirmed, from where, when, whether/when the resulting session was claimed) is permanent — nothing about a QR event is ephemeral or unrecorded |

**A screenshot of an old QR must NOT become a permanent authentication credential — verified directly:** `QrPairingTest::test_confirming_an_already_confirmed_qr_is_rejected_replay_protection` proves a second confirm attempt against an already-used token is rejected AND that the original confirmer's session is what survives, not the replayer's.

## Part 10 — device pairing flow, implemented exactly as diagrammed in the mission brief

```
Desktop/Web: "Login with 1CallFix App"
   │
   ├─ POST /api/auth/qr/create  (unauthenticated — nothing is logged in yet)
   │     → returns TWO distinct secrets:
   │         qr_token    (rendered into the QR image)
   │         poll_token  (kept by the desktop ONLY — never in the QR)
   │
   ├─ Desktop displays QR (qr_token) and polls
   │     GET /api/auth/qr/status?poll_token=...   (repeatedly, cheap, no session revealed)
   │
   ├─ Mobile app (already authenticated via its own OTP login) scans the QR
   │     → POST /api/auth/qr/confirm  (auth:sanctum — the mobile session vouches)
   │        body: { qr_token }
   │
   ├─ Desktop's next poll sees status=confirmed, ready_to_claim=true
   │
   └─ POST /api/auth/qr/claim  (poll_token) — ONE TIME ONLY
         → issues a real Sanctum token for the confirming user
         → challenge marked session_claimed_at; any further claim attempt fails
```

**Why two tokens, not one (a real design decision made this session, not established by any reference):** a single shared token would mean anyone who merely *sees* the displayed QR — a photo, a shoulder-surf, a screen-share — could poll for and steal the resulting desktop session the instant it's confirmed, without ever touching the phone at all. Splitting scan-authority (`qr_token`) from retrieve-authority (`poll_token`, HTTP-response-only, never rendered as an image) closes that gap. This is the single most important security decision in this design and is verified directly: `QrPairingTest::test_status_by_poll_token_starts_pending_and_never_exposes_a_session` and `test_create_returns_two_distinct_tokens`.

## Part 9 — permanent user identity QR: recommendation

**Recommendation: NOT a permanent credential, ever — a static visual identifier only, resolving through the SAME short-lived-challenge pattern for any actual privileged use.** Concretely: a Customer/Partner/Worker's "identity QR" (if built) should encode only a non-sensitive, non-guessable public identifier (e.g. `users.uuid`, already exists, already opaque) — scanning it alone must grant nothing and expose nothing sensitive. Any actual privileged action triggered by scanning it (e.g. an admin confirming a Worker's identity in person before allowing them to start a shift) should go through a fresh challenge exactly like device pairing does, not a static "the QR itself is proof" model. **Designed, not implemented this session** — no existing operational need was found to justify building it now (the mission's own instruction is to design, and implement only what's technically safe AND needed; a static identity QR with no consumer yet would be speculative).

## What's genuinely FUTURE, not implemented, and why that's correct

Job-verification QR (service start/completion, Parcel pickup/delivery) and identity QR are both designed above and deliberately not built — the mission's own rules forbid implementing Parcel/Taxi business logic, and the existing OTP-based Service verification is real, working, and not blocking mobile app development. Building QR job-verification now would be scope creep beyond "the authentication foundation," which is what this session was scoped to.
