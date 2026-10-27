# Release 2026-10-27 — An existing customer can become a partner; Forgot password on every sign-in

Use with `docs/DEPLOY_RUNBOOK.md`. This release also carries the not-yet-deployed **Overdue partners** release
(`docs/deploys/2026-10-27-overdue-partners.md`): do its browser checks too.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `e4da33e` |
| Full test suite | see the merge commit message |
| Composer | no |
| Front-end build (steps 6–7) | yes (overdue-partners classes + the new notice) |
| Version | `VERSION` file bumped to `2026.10.27.2` |

## What's in it
Owner report 2026-10-27: an applicant (number 9397391944, a customer account since 14 Oct) could not join. Sign-up said
"An account with this mobile number already exists. Please sign in instead", and partner sign-in said "Those details
do not match an account". The partner sign-in page also had no "Forgot password?".
- **An existing customer applies on the same account.** After the OTP proves the number, the sign-up shows
  "Applying with your 1CallFix account <name>" and hides name, email and password. On submit a pending provider (or
  rider) profile with the documents is added to that account. Password, name, role and bookings stay the same. After
  approval the partner signs in on partner sign-in with the password they already use.
- Still refused with "sign in instead": a number that is already a partner or rider, a suspended account, or staff.
- **Forgot password?** on every sign-in: customer (already there), partner, rider and admin (new), and in the new
  notice. After the reset it returns to the sign-in it came from ("Back to sign in" too) and shows "Password updated".
- **Super Admin passwords are never reset by OTP** (web and app API): the page says "This account's password can't be
  reset here." Super Admin recovery stays on the server: `php artisan admin:create <email> <new password>`.
  Franchise owners and staff reset their own.
- Partner broadcasts (Admin → notifications to providers) now reach such a partner (counted by provider profile, not
  by account role).
- Unchanged: OTP, documents, admin review and approval, dispatch, payments.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: `0`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Partner, rider and admin sign-in each show "Forgot password?" next to Password; each opens the reset page, whose
  "Back to sign in" returns to that same sign-in.
- `test.franchise@1callfix.test` can use "Forgot password?" from admin sign-in (email code) and lands back on admin
  sign-in. A Super Admin email gets "can't be reset here".
- Ask the applicant with 9397391944 to open "Become a partner" again: after the OTP they see "Applying with your
  1CallFix account …" and no password boxes; they finish with documents. Then Admin → Partners → Businesses →
  Needs review lists them. After approval they sign in on partner sign-in with their usual password.
- Test login: no new role. `test.provider@1callfix.test` (from `php artisan test-accounts`) still signs in on partner
  sign-in; a test customer cannot walk the OTP step (Firebase sends a real SMS), so the live check uses the applicant.

## Admin settings to do after deploy (owner)
- none
