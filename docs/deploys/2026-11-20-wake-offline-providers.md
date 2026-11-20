# Release 2026-11-20.2 — Nobody online? Alert offline providers

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `b57a104` (2026.11.20.1 call-backs + stay signed in) |
| Full test suite | 5041/5042 passed (2026-11-20, final code); known failure only: NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default (.env) |
| Composer | no (`composer.lock` unchanged) |
| Front-end build (steps 6–7) | yes (new box on Admin → Availability & shifts) |
| Version | `VERSION` 2026.11.20.2 |

## What's in it
### Wake offline providers when a job finds nobody online (owner 2026-11-20)
- Before: a "book now" job that found no online provider went to no one; after the search window the customer got
  "no provider found". Providers with the app closed were skipped (auto-offline; owner raised it 30 → 240 minutes
  on Admin → Availability & shifts the same day).
- Now: the first time a search round finds nobody online, approved, active providers in that zone with that skill
  who are offline (or whose location is stale), not busy, within the zone's dispatch radius by last known location
  (never-located ones at the edge), up to 20 nearest, get ONE loud push on the Partner app:
  "🔔 New job near you — AC repair job in Kothapet. Open the app and go online to take it." Tapping opens the
  partner home (Go online switch). Once online they are offered the job on the next round the normal way; first to
  accept gets it.
- Once per job (never repeated), push only, never at night: quiet hours in the franchise's own time zone, default
  22:00–07:00. Scheduled ("later") bookings are not touched (their offers already reach offline providers).
- The booking's history says "Nobody was online: alert sent to N offline provider(s) ...".
- Admin → Availability & shifts: "🔔 Nobody online? Alert offline providers" switch (on by default) and the night
  hours. Audit-logged like the other settings there.
- Load: runs only on a search round that found nobody, one query over the zone's providers, at most 20 queued
  pushes, once per job.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Admin → Availability & shifts: the blue "Nobody online? Alert offline providers" box, switch on, 22:00 to 07:00.
- Walk-through (daytime, owner): Partner app signed in as a service partner with the AC skill, Go offline, close the
  app. Book an AC job for "now" in the same area as a customer. Within a minute the phone rings "New job near you".
  Tap → Go online → the job offer arrives → accept (or decline and cancel the test booking).

## Admin settings to do after deploy (owner)
- none (on by default, 22:00–07:00). Change the night hours there if needed.
