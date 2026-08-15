# Glover vs. 1CallFix — Authentication/OTP/QR Audit

**Superseded premise (2026-08-15, mission Phase 13):** this document's core finding — that no Glover/6amMart reference material was accessible — is no longer true. Phase 13's "Glover/6amMart parity audit" found the real reference codebases (and a real prior-version 1CallFix production database) on disk, outside this git repo. See `GLOVER_6AMMART_PARITY_AUDIT.md` for the full, evidence-based comparison this document's own "Recommendation for the record" (bottom) called for. This document is kept for history — its narrow auth/OTP/QR scope wasn't re-audited against the newly-found material, so its specific rows below are not necessarily still accurate, only its now-corrected "material was inaccessible" framing.

## Availability of reference material — stated honestly, not assumed

This task's brief referenced "a Glover/reference video... provided in this conversation" and "Glover/reference documentation in the project/library." **Neither was actually accessible in this session.** Checked directly: no video file (`*.mp4`/`*.mov`) exists anywhere in the repository, no file or directory matching `*glover*` exists anywhere in the repository, and this tool session has no video-viewing capability regardless. `PROJECT_HANDOFF.md` (this project's own historical documentation, written by an earlier session) describes Glover as "1CallFix's existing multi-vendor codebase... studied early on for dispatch logic, schema patterns, vendor-type structure" and "a local `1.8.5 Glover` folder" — but that folder is not part of this repository and was not accessible here either.

**Every Glover-attributed fact in this document is therefore `NOT ESTABLISHED BY REFERENCE`, per this task's own explicit instruction for exactly this situation** — not guessed, not filled in from general knowledge of how "apps like this" typically work. Where a design decision is needed, it's made from 1CallFix's own current, real architecture instead (see `QR_SCAN_ARCHITECTURE.md`, `OTP_ARCHITECTURE.md`), and labeled as a 1CallFix-native decision, not a Glover-derived one.

## What IS established — from 1CallFix's own repository, not Glover

| Question the brief asks Glover to answer | Answer, from 1CallFix's real code |
|---|---|
| QR usage | NOT ESTABLISHED BY REFERENCE. Zero QR code exists in 1CallFix today (`AUTH_FORENSIC_DISCOVERY.md`) — this is a from-scratch design for 1CallFix, not an extraction from an existing pattern. |
| Login flow | NOT ESTABLISHED BY REFERENCE (Glover). 1CallFix's own login flow: admin-only, session-based, pre-existing, out of this task's scope. No Customer/Partner/Worker login exists yet in 1CallFix. |
| Identity flow | NOT ESTABLISHED BY REFERENCE. |
| OTP behavior | NOT ESTABLISHED BY REFERENCE (Glover). 1CallFix's own OTP behavior is fully traced in `AUTH_FORENSIC_DISCOVERY.md`/`OTP_ARCHITECTURE.md` — a real, working, but narrowly-scoped (Service booking only) implementation with a documented, real gap (OTP never reaches the customer). |
| Notification behavior | NOT ESTABLISHED BY REFERENCE (Glover). 1CallFix's own notification behavior: a real `SmsAdapter`/`PushAdapter`/`ChannelResolver` abstraction, currently bound to log-only adapters (see `NOTIFICATION_ARCHITECTURE.md`). |
| Firebase usage | NOT ESTABLISHED BY REFERENCE (Glover). 1CallFix has zero Firebase configuration anywhere (confirmed via `config/services.php`). |
| Verification behavior | NOT ESTABLISHED BY REFERENCE. |
| Job completion verification | NOT ESTABLISHED BY REFERENCE (Glover). 1CallFix's own: OTP-based, traced fully in `OTP_ARCHITECTURE.md`. |
| Scan workflow | NOT ESTABLISHED BY REFERENCE. |
| Recovery behavior | NOT ESTABLISHED BY REFERENCE (Glover). 1CallFix's own recovery behavior for booking OTP failure is minimal today — see `OTP_ARCHITECTURE.md`'s failure-recovery section for what exists vs. what's designed as a safe addition. |

## Consequence for this task's design decisions

Every design choice in `QR_SCAN_ARCHITECTURE.md`, `OTP_ARCHITECTURE.md`, `AUTHENTICATION_ARCHITECTURE.md`, and `NOTIFICATION_ARCHITECTURE.md` is derived from three things only: (1) 1CallFix's own existing, working architecture (RBAC's scope model, the `Setting::get()` cascade, the `WalletService`-style "one authority, everything routes through it" pattern, the existing `SmsAdapter`/`PushAdapter` abstraction), (2) general, well-established security engineering practice for OTP/challenge-response systems (short-lived tokens, one-time use, hashed storage — not "what Glover does" but "what any correct implementation of this pattern does"), and (3) explicit non-invention of any commercial or UX decision the brief didn't authorize. Nothing here claims Glover-derived authority it doesn't have.

## Recommendation for the record

If a real Glover reference (video or exported documentation) becomes available in a future session, this document should be revisited and its `NOT ESTABLISHED BY REFERENCE` rows updated with real findings — the architecture decisions made without it in this session are sound on their own merits, but a genuine comparison against a real system 1CallFix's team has direct experience with could still surface useful refinements this session had no way to know about.
