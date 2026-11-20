# Release 2026-11-20.3 — Referrals page states the minimum job value

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `d1edcfe` (2026.11.20.2 wake offline providers) |
| Full test suite | 5043/5043 passed (2026-11-20, final code), no known failures |
| Composer | no (`composer.lock` unchanged) |
| Front-end build (steps 6–7) | no (the line uses `font-semibold text-slate-800`, already in the live CSS and already on this page) |
| Version | `VERSION` 2026.11.20.3 |

## What's in it
### Referrals page: the minimum job value in plain words (owner 2026-11-20)
- Customer → Earnings → Referrals, "How referrals work": a new bold line
  "Only your friend's service booking of ₹700 or more counts. A smaller booking doesn't use up the reward."
  The amount is read live from Earnings Control → Referrals (`referral.min_job_value`): change it there and the
  page follows. Whole rupees show without decimals (₹700), anything else with two (₹1,250.50). Blank or 0 = no
  line.
- Wording follows the code: ANY completed service booking of the friend that meets the rules counts, not only the
  first; a smaller one leaves the referral pending (`ReferralService::qualificationProblem`).
- "completes a booking" now reads "completes a service booking": referral rewards are earned and spent on service
  bookings only (taxi, parcel, shop, food, hotel and rentals are not wired, by design).
- The old "of at least ₹700.00" inside the long sentence is removed (now its own line).

### Settings changed on live the same day (no code; audited via SettingsAuditor, actor Super Admin #3)
- HSTS on (`security.headers.hsts`, 1 year, no subdomains).
- Rent anything on for zone 41 Nellore Main (`rental.kind.items`).
- Referrals on: ₹100 referrer + ₹100 friend (referral balance), max 25 rewarded per customer, minimum job ₹700,
  online or cash jobs, referral balance pays up to 20% of a booking, admin flag above 10 referrals, Referrals tab on.
- Coupon WELCOME (#4): 20% off up to ₹150, minimum ₹1,000, new customers only, once each, services, until
  2026-11-27 23:59 IST.
- Server `.env`: `LOG_LEVEL=info` (was debug), 3 duplicate lines removed; backup `~/backups/env-before-loglevel-20261120-202413`.
- 62 old failed jobs flushed (59 from the 10 Nov SMTP outage); backup `~/backups/failed_jobs-before-flush-20261120-202500.sql`.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Sign in as `test.customer@1callfix.test` → Account → Earnings → Referrals: "How referrals work" shows
  "completes a service booking" and the bold line "Only your friend's service booking of ₹700 or more counts. A
  smaller booking doesn't use up the reward."

## Admin settings to do after deploy (owner)
- none
