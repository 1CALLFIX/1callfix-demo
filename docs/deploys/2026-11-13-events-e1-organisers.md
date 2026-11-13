# Release 2026-11-13 (3) — Events E1: organisers join, build events and ticket types; admin approval

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `b54a4d5` |
| Full test suite | 4803 passed of 4804; known failures only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (organiser portal, admin Events screen) |
| Version | `VERSION` file bumped to `2026.11.13.3` |

## What's in it
Owner 2026-11-13: event ticket sales ("Year End Bash") as their own module, Events. Step 1 of 3 — nobody can buy yet.
- /partners: new card "Event organiser" (Opening soon until Event Settings → "Take event organiser sign-ups" is on).
- Organiser portal `/organiser` (partner sign-in): Home, Events (create, ticket types, send for review), Organiser details.
- Admin → Events → Events & organisers: approve organisers and events, send back with a reason, feature.
- Admin → Events → Event Settings (Super Admin only): commission 10%, payout 48 h after the event, buyer cancellation
  off, GST bands (blank = no sale), and the rest.
- Dashboard tile "Event organisers" (shows once one is approved).
- Permission `events.manage`: Super Admin and every role that already approves hotels.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_14_100000_create_events_module`

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- none new

## Test login
- `php artisan test-accounts create --password=...` adds `test.organiser@1callfix.test` (approved organiser) with
  "Test Year End Bash" waiting for review. Sign in at /provider/login → opens /organiser.

## Browser checks after deploy
- /partners: "Event organiser" card shows "Opening soon".
- Admin → Events → Events & organisers opens (Events and Organisers tabs); Event Settings opens for Super Admin.

## Admin settings to do after deploy (owner)
- When ready to take organisers: Event Settings → "Take event organiser sign-ups" on.
- Event Settings → GST bands (check with your CA) before step 2 goes live.
