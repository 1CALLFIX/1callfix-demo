# Release 2026-11-25 — Job history on the partner Jobs tab

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `9a14bb7` |
| Full test suite | passed 5,253 of 5,253; known failures only: none |
| Composer | composer.lock changed: no |
| Front-end build (steps 6–7) | no — every class the new screens use is already in the live build (`app-CNS-_MDI.css`, checked 2026-11-25) |
| Version | `VERSION` file bumped to `2026.11.25.1` |

## What's in it
- Owner 2026-11-25: a partner looking for past jobs found nothing on Jobs; Job history was only under More.
- Jobs tab: two big tabs at the top, green "New offers" (with a count while offers wait) and blue "Job history".
  The empty "No live offers" card has a "See your past jobs →" button.
- Job history keeps the Jobs tab lit in the bottom bar (was More). Still listed under More too.
- Job history rows: date and time (franchise time zone), amount (final, else quoted), coloured status badge
  (green completed, red cancelled, blue active); bigger filter buttons.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Partner app / www.1callfix.com/provider/jobs: both tabs show; Job history opens /provider/history with Jobs lit.
- Test login: test.provider (6000000108), password from the owner.

## Admin settings to do after deploy (owner)
- none
