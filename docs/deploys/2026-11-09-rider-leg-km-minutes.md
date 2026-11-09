# Release 2026-11-09 — km and minutes on the rider's food / shop card

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `9abd301` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | yes if the CSS file name changes, else skip |
| Version | `VERSION` 2026.11.09.9 |

## What's in it
- Owner (photo of the card): beside **1. Pickup** a pill "2 km · 8 min" (rider → restaurant / shop, until picked up);
  beside **2. Deliver** "2.2 km · 9 min" (rider → customer once picked up; restaurant → customer before).
- km is straight-line (same as "Drop: 3.2 km away"); minutes allow for roads (x1.3) at the new Super Admin setting
  Shop Settings → Delivery → **Rider speed** (default 20 km/h). Nothing shows when the rider's phone has not sent a
  location for 15 minutes (it sends every 25 s on a job).

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Rider app, a food job before pickup: "1. Pickup  x km · y min"; after Handed over: "2. Deliver  x km · y min".
