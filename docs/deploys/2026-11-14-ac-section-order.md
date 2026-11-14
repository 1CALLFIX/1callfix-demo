# Release 2026-11-14 — AC section order fix

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `3494b76e` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | no |
| Version | `VERSION` file bumped to `2026.11.14.4` |

## What's in it
- Fix for the AC one page (`3494b76e`): production had every service's sort number at 0, so the sections showed in
  name order (1 Repair & gas, 2 Service, 3 Install & move). A data migration gives each AC section its own sort
  number (subcategory's lowest + 0 / 1 / 2), so Split AC / Window AC / Other AC show **1 Service, 2 Repair & gas,
  3 Install & move**. Only AC services with one of these sections move; prices untouched.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_17_100200_ac_section_order`

Rollback count for `migrate:rollback --step=`: `1` (sort numbers stay; display only)

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- /nellore/services/appliance-ac-repair?sub=1 (Split AC): sections in order 1 Service, 2 Repair & gas, 3 Install & move.
- Window AC and Other AC: Service first as well.

## Admin settings to do after deploy (owner)
- None.
