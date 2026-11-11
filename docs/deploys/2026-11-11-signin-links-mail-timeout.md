# Release 2026-11-11 — sign-in join links + mail timeout

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `45946da` |
| Full test suite | 4682 tests, 4679 passed; RiderPaymentsTest link assertion updated (passes); DispatchDeadlineSweepServiceTest passes alone (25/25, parallel-timing flake); known `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | no (Blade + config only; no new CSS classes) |
| Version | `VERSION` 2026.11.11.1 |

## What's in it
- Rider sign-in: "New to 1CallFix? Become a rider" → /partners?role=parcel (Delivery rider picked). Partner sign-in
  "Become a partner" → role=service; shop/restaurant sign-in "Open a shop" (commerce) · "Add your restaurant" (food).
  Role cards on /partners highlight the asked role.
- "Not a rider / partner / seller? Customer sign in" shows on the website only, not inside the Rider / Partner apps.
- Mail: SMTP waits at most 5 s (`MAIL_TIMEOUT`, default 5). 2026-11-10 trial: 1callfix.com was behind Cloudflare
  (orange cloud), SMTP host `1CallFix.com:587` unreachable, each booking email waited ~60 s inside the partner's tap
  (Accept ~4 min; Start looked stuck on "On the way", booking #210). Orange cloud switched off 2026-11-11 (Airtel
  traffic was routed via Marseille).

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Rider app sign-in: "Become a rider" opens /partners with Delivery rider selected; no "Customer sign in" line in the app.
- Partner app sign-in: no "Customer sign in" line; "Become a partner" opens Service professional.
- A service booking: Accept, On my way, Start each respond within a couple of seconds.

## Follow-ups (not in this release)
- Send booking emails / OTP notifications in the background (queued) so a tap never waits for mail.
- MAIL_HOST: certificate on :587 is for srv1422426.hstgr.cloud, not 1callfix.com; switch after a test email.
- Re-enabling Cloudflare proxy needs MAIL_HOST off the proxied name first, and Indian routing checked (Airtel → MRS).
