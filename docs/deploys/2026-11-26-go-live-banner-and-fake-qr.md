# Release 2026-11-26 (4) — no "Go live" banner for approved partners; one wording for a fake QR

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `bab0768` |
| Full test suite | passed 5,287 of 5,287; known failures only: none |
| Composer | composer.lock changed: **no** |
| Front-end build (steps 6–7) | no (Blade text and logic only, no new classes) |
| Version | `VERSION` file bumped to `2026.11.26.4` |

## What's in it
- Found in the 2026-11-26 walk-through: the shop Home showed "Go live: upload 2 documents so we can approve you" right
  above its "Verified Partner certificate". The banner counted missing documents even after 1CallFix approved the
  partner. It now shows only while the partner is not yet approved (`kyc_status` not `approved`), for the Partner,
  Rider, Shop, Hotel, Rental and Events apps. On live all 12 providers and 4 riders are approved, so it disappears
  for everyone who was approved with a document missing.
- A fake or old QR (`/verify/...`) could be an ID card or a shop's certificate, so the red page now says "Not a valid
  card or certificate … book or order only through the 1CallFix app" instead of the ID-card-only "Do not let them
  start work".

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- test.seller Home: the 🏅 certificate card, and no "Go live: upload … documents" line.
- https://1callfix.com/verify/abc123notreal: red "Not a valid card or certificate".
- Still to do from the last release (owner, with the test password): `php artisan test-accounts create --password=...`
  so test.provider and test.rider get the approved ID photo and their ID card shows on live.
