# Release 2026-11-13 (2) — Dashboard: a tile per kind of business

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `639b121` |
| Full test suite | 4790 passed of 4791; known failures only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (new grid column classes) |
| Version | `VERSION` file bumped to `2026.11.13.2` |

## What's in it
Owner 2026-11-13: the "Shops & restaurants open" tile on the admin dashboard is split into **Shops open**,
**Restaurants open** and **Hotels live** (approved hotels switched on). A business tile shows once at least one of
that kind has joined (approved); Riders and Partners on duty always show. Each tile opens its list.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: `0`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Admin → Dashboard (All modules): Riders on duty, Partners on duty, Shops open, Restaurants open, Hotels live.

## Admin settings to do after deploy (owner)
- none
