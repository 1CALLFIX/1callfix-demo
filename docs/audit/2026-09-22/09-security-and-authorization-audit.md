# 09 — Security and Authorization Audit

**Ref:** `1CF-COMPREHENSIVE-AUDIT-20260922-1110` · Read-only, no code changed. No production system
was accessed — this session has no SSH/production access (an established, standing constraint from
prior sessions); every finding below is repository evidence only, distinguished from runtime
verification where that distinction matters.

## 1. CRITICAL — live production credential committed in plaintext

**Status: Unsafe, confirmed live in the current working tree (not historical-only).**

`scripts/backup-database.sh` line 16 contains a hardcoded, real-looking production database
password (`DB_PASS="..."`), used directly in a `mysqldump -u "$DB_USER" -p"$DB_PASS"` invocation.
This was previously flagged in project history (as a git-history-only concern) but is **confirmed
in this pass to still be present in the current working file**, not just in old commits. Anyone
with read access to this repository (which includes its full commit history regardless of the
current file state) has this credential.

**This needs to be treated as already compromised** — rotating the database password is the only
real fix; removing it from the current file does not remove it from history without a history
rewrite (`git filter-repo` or equivalent), which is a separate, disruptive, and higher-risk
operation than this audit's scope. Recommend: (1) rotate the credential immediately regardless of
any code change, (2) replace the script's hardcoded value with `${DB_PASS:?set DB_PASS in
environment}` or read from Laravel's own `.env`, (3) decide separately, with the business, whether
a history rewrite is warranted given who has had repository access.

## 2. Account suspension enforcement — partially live, partially unmerged

**Status: Implemented but incomplete.**

- **On `main` today:** `app/Livewire/Auth/Login.php:71` checks `$user->status === 'suspended'` and
  blocks the password-login path. This is a real, working gate for the primary login surface.
- **Not on `main`:** the unmerged `feature/membership-prime-silver` branch (see doc 08 §3) carries
  three additional suspension-hardening commits not reflected above: "Enforce suspension checks on
  persistent Livewire requests," "Enforce account suspension across every authentication surface,"
  and "Enforce provider eligibility at booking acceptance." Their existence, on an unmerged branch,
  is itself evidence that the *current* live gate is known-incomplete — likely covering: a user who
  is already logged in (persistent session/remember-me) when suspended mid-session, Google/Firebase
  OAuth login paths, and provider-side acceptance of a job despite a suspension that should block it.
- **Also unmerged:** a fifth commit on the same branch, "Add server-side location freshness gate to
  Services dispatch," and a sixth, "Close provider offer timeout race at acceptance" — both dispatch
  integrity fixes for issues presumably found and fixed but never shipped.

**These six commits (`f626cf5`, `657a8a6`, `654889b`, `5e45ee7`, `394e926`, `31f9265` — the last
being a provider-offer-listing feature rather than a fix) are bundled onto a membership feature
branch and have not reached `main`.** Recommend splitting them out and merging the security/
integrity fixes on their own, faster track — independent of whatever the membership business
decision (doc 08 §5) ends up being.

## 3. Cash-commission financial controls

Covered in full in doc 04. Headline for this section: no dispatch-eligibility restriction exists
for a provider with unresolved cash debt (§2.6 of doc 04) — a financial-integrity gap with a
security/fairness dimension (a provider who never remits can keep working indefinitely without
consequence).

## 4. Rate limiting

**Status: Implemented and verified** for the sensitive authentication surface. `routes/api.php`
applies `throttle` middleware to every auth-adjacent endpoint checked: password login (`10,1`),
Firebase login (`20,1`), registration (`10,1`), password forgot/reset (`5,1` each), OTP
request/verify (`5,1`/`10,1`), QR auth create/status/claim (`10,1`/`60,1`/`20,1`). This is a
reasonably conservative, well-thought-out set of limits — not a gap.

## 5. FCM / push notification production configuration

**Status: Unverified (deployment question, not a code gap).** `.env.example` documents
`FIREBASE_WEB_VAPID_KEY`, `FCM_PROJECT_ID`, `FCM_CREDENTIALS_PATH`/`FCM_CREDENTIALS_JSON` as
commented-out placeholders — the code path exists (confirmed in prior session work, Phase PN2), but
whether these are actually populated in the production `.env` cannot be verified from this session
(no production access). Carried forward from prior audits as a standing open item, not newly
discovered.

## 6. Not independently re-audited this pass (explicitly flagged, not silently skipped)

Per the brief's full list in §11, the following were **not** given dedicated verification in this
pass, beyond what earlier sections of this audit touched incidentally:
- CSRF protection — Laravel's default (`VerifyCsrfToken` middleware) is presumably active project-
  wide; not independently confirmed no route bypasses it.
- XSS protection — Blade's default auto-escaping is presumably relied on throughout; not swept for
  raw `{!! !!}` output on user-controlled data.
- IDOR — spot-checked incidentally in several places this pass (e.g. `Checkout.php`'s address
  ownership check at line 135-139, `ServiceShow`'s auth-gated cart add), which is a good sign, but
  no systematic sweep of every customer-facing/provider-facing controller and Livewire component for
  a missing ownership check was performed.
- Tenant/country/city/zone scoping — spot-checked via `AuthorizationService::canWithRestrictedScope()`
  calls seen in doc 06's sampled delete actions (a real, consistently-used scoping pattern), not
  swept across every admin action.
- File uploads, logs, backups (beyond §1), monitoring, error handling — not touched this pass.
- Redis / queue worker health, failed-jobs table state — not touched this pass (relevant to doc 05's
  dispatch reliability question; recommend a dedicated pass).

These are not claimed as either safe or unsafe — they are **unverified**, and the roadmap (doc 12)
should schedule a dedicated security-review pass (the `code-review`/`security-review` project
tooling already available in this environment is well suited to that, as its own controlled phase)
rather than have this already-large audit absorb it superficially.

## 7. Summary

| Item | Status |
|---|---|
| Production DB password in plaintext, live in working tree | **Critical — confirmed, action required regardless of this audit's roadmap** |
| Password-login suspension gate | Implemented and verified |
| Suspension gate on all other auth surfaces + persistent sessions | **Missing on `main`** (built, unmerged) |
| Provider-eligibility enforcement at job acceptance | **Missing on `main`** (built, unmerged) |
| Dispatch offer-timeout race at acceptance | **Missing on `main`** (built, unmerged — fix exists) |
| Rate limiting on auth endpoints | Implemented and verified |
| FCM/VAPID prod config | Unverified (deployment, not code) |
| CSRF/XSS/IDOR/tenant-scoping systematic sweep | Unverified — explicitly out of this pass's depth, recommended as its own phase |

## 8. Required changes (roadmap input)

| # | Change | Risk | Urgency |
|---|---|---|---|
| 1 | Rotate the production DB password; parameterize `backup-database.sh` off `.env` | Low (script change) / the rotation itself is an ops action | **Immediate — independent of any other roadmap phase** |
| 2 | Cherry-pick/merge the six suspension + dispatch-integrity fixes from `feature/membership-prime-silver` onto `main` on their own, without waiting for the membership business decision | Low-medium — these are already-written, presumably-tested fixes; needs a clean review pass since they've been sitting unmerged | High |
| 3 | Schedule a dedicated CSRF/XSS/IDOR/tenant-scoping sweep as its own audit phase | — | Medium |
| 4 | Confirm production FCM/VAPID configuration status with whoever has prod access | — (ops confirmation only) | Medium |
