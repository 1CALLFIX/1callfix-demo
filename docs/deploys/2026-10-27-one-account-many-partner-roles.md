# Release 2026-10-27 — One account, many partner roles; "Become a partner" in the header

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `cce5128` |
| Full test suite | 4135 of 4136 pass; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (header button, amber notices) |
| Version | `VERSION` file bumped to `2026.10.27.3` |

## What's in it
Owner decisions 2026-10-27: people do not have two mobiles or two emails, so **one account holds every partner role**;
anyone, a signed-in customer too, joins from a header button; any partner can also deliver parcels.
- **Header "Become a partner"** (desktop next to Book Now, phones in the menu) → the partner page. Shown signed out,
  and to a signed-in account that can still add a role. Super Admin: Admin → Homepage & sign-up → "Become a partner
  button" (show / text), audit-logged.
- **Signed-in customer joins with the same account**: the partner page form starts with their name and mobile; the
  sign-up **always sends a fresh code to the account's own number** (owner: verification is a must for a partner; a
  phone left signed in is not proof); a code for another number is refused; no password boxes; bookings and
  password stay.
- **Several roles on one account**: a service partner can also apply as a rider and a rider can add services (rider
  documents still required once: driving licence etc.). The same role twice is refused; staff and suspended accounts
  never apply.
- **Mode switch**: partner dashboard shows "Switch to rider mode" (or "Deliver parcels too" while riders are being
  recruited); rider dashboard shows "Switch to partner mode" (or "Offer services too"). One sign-in for both.
- **Partner / rider sign-in no longer a dead end**: right password on an account without that role → amber message and
  "Finish your partner application" / "Apply as a rider".
- **Reset password page**: "Sending a code to …" first, "Enter the code sent to …" only once Firebase confirms; a Firebase
  refusal shows alone.
- Later (Food module, not built): a restaurant owner with rider mode can deliver their own order when no rider takes it.
- Unchanged: OTP for new numbers, documents, admin review and approval, dispatch, payments.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: `0`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Homepage signed out: "Become a partner" in the header (phone: in the menu) → /partners.
- Signed in as a customer: the same button; /partners form shows your name and mobile; Service professional → sign-up
  asks for a code to your own number (no way to change it), then no password boxes.
- Partner sign-in with a customer's number and password: amber message + "Finish your partner application".
- `test.provider@1callfix.test` partner dashboard: "Deliver parcels too" (while rider sign-ups are open).
- Admin → Homepage & sign-up → "Become a partner button": switch off → the header button disappears.

## Admin settings to do after deploy (owner)
- Optional: change the button text.
