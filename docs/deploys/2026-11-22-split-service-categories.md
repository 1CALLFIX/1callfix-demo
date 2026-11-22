# Release 2026-11-22.9 — Separate service categories (no more "|" names)

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `ed4191f` (2026.11.22.8) |
| Full test suite | passed 5171 of 5171 (local, 2026-11-22); known failures: none |
| Composer | no |
| Migrations | one, data only (below) |
| Front-end build (steps 6–7) | no |
| Scheduled commands | none new |
| Version | `VERSION` 2026.11.22.9 |

## What's in it
Owner 2026-11-22: "make them separate that is better" — "Laptop, Computer, CCTV all should be separate since in previous
app we added many not working services categories subcategories due to which we clubbed them together"; subcategory
names cleaned too.

| Before (live) | After |
|---|---|
| Appliance \| AC Repair (id 1, 12 subcategories, 46 services) | **AC Repair** (id 1 kept: Split AC, Window AC, Other AC) + **Appliance Repair** (new: Washing Machine, Fridge, TV, Geyser, Inverter, Mixie, Water Purifier, Air Cooler, Chimney) |
| Laptop\| CCTV \| IT Services (id 3, 8 subcategories, no services) | **Laptop Repair** (id 3 kept) + **Computer Repair** (Desktop, Printer) + **CCTV** (CCTV Service) + **IT & Networking** (Networking, Wi-Fi Setup, OnCall Support, Breakdown Help) |
| Mens Salon \| Beauty Care, Womens Salon \| Beauty Care, DJ \| Lighting | Men's Salon, Women's Salon, DJ & Lighting |

- Kept rows keep their ids, so banners, page content and bookings pointing at them are untouched.
- Old links: `/nellore/appliance-ac-repair` → `/nellore/ac-repair`, `/nellore/laptop-cctv-it-services` →
  `/nellore/laptop-repair` (permanent 301, SlugManager); renamed subcategory slugs redirect too.
- Partners: everyone with "Appliance | AC Repair" as a skill (4 on live) also gets Appliance Repair; the one with the IT
  category gets Computer Repair, CCTV and IT & Networking. Nobody stops receiving jobs.
- New categories reuse the original's picture and colour; order is AC, Appliance, Home Repairs, Laptop, Computer,
  CCTV, IT & Networking, Blood Test, Beauty Care, Events.
- Partner FAQ "Which services can I offer?" lists only categories that have a bookable service.
- Every step is in the activity log.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_22_210000_split_combined_service_categories` (data only)

Rollback count for `migrate:rollback --step=`: 1, but `down()` does nothing — to undo, restore the step-2 backup.

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- https://1callfix.com/nellore/appliance-ac-repair → 301 to /nellore/ac-repair; AC Repair shows Split / Window / Other AC.
- /nellore/appliance-repair shows Washing Machine … Chimney with their services.
- Home page category cards: AC Repair, Appliance Repair, …; no "|" in any name.

## Admin settings to do after deploy (owner)
1. Give Appliance Repair, Computer Repair, CCTV and IT & Networking their own pictures (they start with the old one).
2. Laptop / Computer / CCTV / IT & Networking, Blood Test, Beauty Care and Events have no services yet: add services, or
   switch the empty ones off so customers do not open an empty category.
