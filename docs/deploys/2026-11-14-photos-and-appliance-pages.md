# Release 2026-11-14 — new category photos + one page for every appliance

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `0443f317` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | no |
| Version | `VERSION` file bumped to `2026.11.14.5` |

## What's in it
- New HD photos (owner picked them on the photo check page, 2026-11-14): 6 categories + 25 subcategories get an
  Unsplash photo; 17 marked "None fit" keep theirs (Window AC, Other AC, Geyser Repair, Water Purifier, Air Cooler,
  Chimney, Plumber, Packers n Movers, Home Shifting, All items Installation, Artificial & Landscaping, Breakdown Help,
  Popular Packages, Mens Salon, Mens Bridal Makeup, Womens Salon, Womens Bridal Makeup). A row changes only if it
  still has the photo it had when picked; `migrate:rollback --step=2` puts the old photos back.
- Owner: the AC one page for every appliance. Washing Machine, Fridge, TV, Geyser, Inverter, Mixie, Water Purifier,
  Air Cooler, Chimney now open as 1 Service, 2 Repair, 3 Install & move with Add buttons. Prices untouched.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_17_100300_category_photos`
- `2026_11_17_100400_appliance_page_sections`

Rollback count for `migrate:rollback --step=`: `2` (photos go back; sections stay, display only)

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Homepage and /nellore/services: new photos on the categories.
- Appliance | AC Repair → Geyser Repair: sections Repair (Gas Geyser Repair, Geyser Repair) and Install & move.
- Chimney: 1 Service, 2 Repair, 3 Install & move. Split AC unchanged.

## Admin settings to do after deploy (owner)
- None. Geyser sizes as their own priced items come in a later release once the owner gives the prices.
