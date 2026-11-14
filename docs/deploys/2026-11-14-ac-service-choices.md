# Release 2026-11-14 — AC service choices

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `6fcf68f3` |
| Full test suite | 4898 passed of 4899; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (new Tailwind classes on the service page and the admin service form) |
| Version | `VERSION` file bumped to `2026.11.14.2` |

## What's in it
- Owner 2026-11-14: Split AC Service shows as ONE card with 5 choice buttons: General service ₹900, Jet pump service
  ₹1,199, Foam + jet pump service ₹1,599, Chemical wash ₹1,799, Full service ₹2,499. Window AC Service: Foam service
  ₹1,199, Chemical wash ₹1,599 (new service "Window AC Chemical Wash", same category, so partners get it like any other).
- Each choice is still its own service: its own price, coupons, flash sales, partner job name and invoice. No money code
  changed. (The older "Options" on a service only change the shown estimate, never the charge, so they were NOT used.)
- Lists (category page, homepage rows, "related") show only the main card; a search still finds each choice.
- Admin → Services → Edit: new "Show as a choice" box (Choice of / Group name on the card / Button text).
- Old links keep working: no service was renamed or switched off.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_16_100000_add_service_choices`
- `2026_11_16_100100_group_ac_service_choices`

Rollback count for `migrate:rollback --step=`: `2` (the new Window AC Chemical Wash service stays; switch it off by hand if needed)

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- /nellore → Services → Appliance: one "Split AC Service" card (no separate Jet / Foam / Chemical / Full cards).
- Open it: "Choose your service" with 5 buttons; tap Chemical wash → price ₹1,799; Add to cart → cart shows Split AC Chemical Wash ₹1,799.
- Window AC Service: 2 buttons, Foam service ₹1,199 and Chemical wash ₹1,599.
- Search "chemical" still finds both chemical wash services.

## Admin settings to do after deploy (owner)
- None required. Optional: Admin → Services → Window AC Chemical Wash → check description and photo.
