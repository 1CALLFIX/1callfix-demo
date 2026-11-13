# Release 2026-11-13 (6) — GST optional for Events and Hotels

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `95eac2c` |
| Full test suite | see runbook step 1 report; known failures only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | no |
| Version | `VERSION` file bumped to `2026.11.13.6` |

## What's in it
Owner 2026-11-13: "make gst optional not mandatory for all so that we can start operations easily and later update
with gst as & when required".
- Event Settings and Hotel Settings → "GST … by price" left blank = no GST is charged; tickets sell and rooms book.
  Before, blank meant nothing could be sold.
- Once bands are entered they must end with a `*` line (every price covered). A saved entry with a gap still refuses
  that price (fail closed).
- Also ships `ce55dfd3`: Admin → Homepage names the events row "Upcoming events in your city".

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: `0`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Admin → Event Settings: GST help says "Blank = no GST is charged".
- With Events on for Nellore and "Test Year End Bash" approved: /nellore/events lists it, the event page shows prices
  without GST and a Continue button.

## Admin settings to do after deploy (owner)
- none required. Add GST bands later when registered.
