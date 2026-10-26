# Release 2026-10-26 — Homepage parcel card + Parcel icon

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `377ed01` |
| Full test suite | 4103 of 4104 pass; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (new classes on the parcel card) |
| Version | `VERSION` file bumped to `2026.10.26.2` |

## What's in it
Owner request 2026-10-26 (Rapido / Borzo references): the homepage parcel box becomes an eye-catching card, and
Parcel gets its own icon. Only the parcel box changes; the rest of the homepage is untouched.
- **Parcel card**: big title, subtitle, picture, "from ₹…" badge (the area's Parcel Settings base fare, hidden when
  unset), chips (Keys, Documents, Lunch box), **Send now** and **Track my parcel** buttons.
- **Parcel icon** as the first category icon, with an optional "NEW" label.
- **/parcel page**: the same picture and subtitle in a header above the unchanged form.
- **Admin → Growth → Homepage & sign-up → Parcel card** (Super Admin only, audit-logged): position (top / after the
  category icons / after the top banner), colour (blue, yellow, green, dark), title, subtitle, chips (typed with
  commas, with an on/off switch), both button words, the label text on the icon ("NEW") and its switch, the icon
  switch, the price switch, the /parcel page heading and line, and upload or reset of the card picture and the icon
  (PNG/JPEG/WebP, max 2 MB; no SVG uploads). Blank text goes back to the default wording. Built-in pictures are our own drawings (`public/images/parcel/`), never Rapido's or Borzo's artwork.
- Defaults keep the card where it is today (after the top banner). Everything shows only where Parcel is switched on
  in Admin → Modules for the browsed zone, as before.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: `0`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Home page in a Parcel zone: the new card after the banner, the Parcel icon first in the category icons with "NEW",
  "from ₹40". Send now opens /parcel; Track my parcel opens My parcels (asks to log in when signed out).
- /parcel shows the picture header; the form below is unchanged.
- Admin → Homepage & sign-up: the Parcel card section saves; set "Top", check the home page, set it back if wanted.
- Uploaded pictures need `public/storage` linked (already used by category icons and banners).

## Admin settings to do after deploy (owner)
- Optional: upload your own card picture and icon; choose the position and colour.
