# Release 2026-11-07 — ♡ Like and Share on the photo, Saved list, clean service descriptions

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `d63d6b2` |
| Full test suite | 4534 passed of 4535 on b8a647c; known failures only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` |
| Composer | no |
| Front-end build (steps 6–7) | yes |
| Version | `VERSION` 2026.11.07.1 |

## What's in it (owner's Flipkart / Meesho / Urban Company / Amazon screenshots, 2026-11-07)
- Two round white buttons, **♡ Like** and **Share** (paper-plane icon, brand blue), on the top-right corner of the
  photo: service, product, restaurant cover (or above the rating when there is no cover), shop, and every dish photo
  (small; a dish without a photo has them under its words). The old "Share" pill under the title is gone on these
  pages; pages without an item photo (categories, city pages, parcel, membership) keep a soft blue "Share" pill.
- ♡ saves the item to **Account → Saved** (also in the ☰ menu). Tap again to remove. A visitor is sent to sign-in and
  back. The heart fills red with a small pop.
- Service descriptions copied from the old app showed raw code (`<h3 style="color:Green;">…`). They now show as
  formatted text (headings, bullet lists, bold); styles, links, images and scripts are dropped.
- Words in Super Admin → Website text, new group "Like (Saved) button" (`saved.*`, `header.menu.saved`, English + Telugu).

## Step 5 — migrations that must be pending
- `2026_11_07_100000_create_saved_items_table`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Split AC Jet Service: ♡ and Share on the photo corner; "About this service" shows INSTRUCTIONS as a heading with
  bullets, no `<h3` code.
- Test Discount Item and Test Restaurant (cover/header and each dish): ♡ and Share on the corner.
- Signed in: tap ♡ → it turns red; Account → Saved lists it; tap ♡ there to remove.
- Tail of storage/logs/laravel.log: no new errors.
