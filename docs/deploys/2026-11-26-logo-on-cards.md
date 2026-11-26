# Release 2026-11-26 (5) — the company logo on the ID card, certificate and stickers

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `c38e11c` |
| Full test suite | passed 5,288 of 5,288; known failures only: none |
| Composer | composer.lock changed: **no** |
| Front-end build (steps 6–7) | no (inline styles only) |
| Version | `VERSION` file bumped to `2026.11.26.5` |

## What's in it
- Owner 2026-11-26: "the company logo is completely missed", then "this is how we need the logo" (the banners):
  one blue pill with a white edge, the round logo inside on the left (white middle, filling the pill height), then
  "1CallFix" (`partner/brand-pill.blade.php`). The round 1CallFix logo (the one uploaded in admin,
  Settings → Branding; its 256 px copy) now sits before the "1CallFix" badge on the ID card front, the certificate and
  both door/counter stickers, and next to the partner ID on the ID card back. On the phone screens and in the PDFs.
  A new logo uploaded in admin shows on them automatically. No logo uploaded: the cards keep the badge only.
- `BrandingAssetService::logoDataUri()` (data URI, so the PDF maker needs no web request).

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- test.seller → My certificate: logo on the certificate and the sticker; Download to print: logo on page 1 and both
  stickers on page 2.
- test.provider → My ID card (after `test-accounts create` on live): logo on the front and the back.
