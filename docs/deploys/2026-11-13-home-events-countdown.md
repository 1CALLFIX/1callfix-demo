# Release 2026-11-13 (5) — Homepage: Upcoming events with a live countdown

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `a6ecf6b` |
| Full test suite | 4820 passed of 4821; known failures only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (new card classes) |
| Version | `VERSION` file bumped to `2026.11.13.5` |

## What's in it
Owner 2026-11-13: an "Upcoming events in {city}" card on the homepage, like Hotels: approved events that have not
started, soonest first, poster, when, venue, and a live days / hrs / min / sec countdown ("Happening now" once
started). Only where Events is switched on (Admin → Modules); on/off and order in Admin → Homepage.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: `0`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Homepage loads; the events card is hidden while Events is off for the city.
- Admin → Homepage lists "Upcoming events in :city".

## Admin settings to do after deploy (owner)
- none (the card appears once Events is switched on and an event is approved)
