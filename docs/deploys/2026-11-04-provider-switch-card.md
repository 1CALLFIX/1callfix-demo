# Release 2026-11-04 — Service partner home: big online switch card

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `e804705` (VERSION 2026.11.04.4) |
| Full test suite | see the commit message |
| Composer | no |
| Migrations | none |
| Front-end build (steps 6–7) | **yes** (new classes in the card) |
| Worker restart (step 9) | yes |
| Version | `2026.11.04.5` |

## What's in it (owner 2026-11-04: "only test provider ui is a little bit different")
- Service partner home (/provider) now opens with the same big Online/Offline switch card as the rider and
  seller apps (green when online, dark when offline). Zone, KYC, "Location updated …" and Refresh location sit
  inside the card.
- The small header Go online button is hidden on the home page only (it stays on Jobs, Earnings, More, ...).
- The old separate "You are offline / Go online" box is gone. No dispatch, heartbeat or money logic changed.

## Step 5 — migrations
- none

## Step 10 — scheduled commands
- none new

## Test logins
- `test.provider` (`php artisan test-accounts`).

## Browser checks after deploy (phone width)
- /provider: big switch card at the top, no Go online button in the header; flip it on and off.
- /provider/jobs: the small header button is still there.
