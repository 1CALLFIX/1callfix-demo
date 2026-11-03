# Release 2026-11-03 — Photo links in the product import files

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `a525273` (seller change rules + add many) — stop if the server shows anything else |
| Full test suite | 4461 passed of 4462 (12 chunks, all 434 files, 2026-11-03); known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | no (text only) |
| Version | `VERSION` file bumped to `2026.11.03.4` |

## What's in it
Owner 2026-11-03: product photos as links to pictures hosted elsewhere (light files, fast pages), as the service
photos already are (ImgBB, `https://i.ibb.co/...`).
- Admin → Products → Import: `image` column, up to 5 links separated by `|`.
- Seller → Products → Change many in Excel: new **photos** column (the download fills in each product's photo links).
- A link must be the **direct** https picture link ending in .jpg / .png / .webp / .gif (`https://i.ibb.co/...`,
  `https://i.postimg.cc/...`). A host's page link (`https://ibb.co/...`, `https://postimg.cc/...`) or an http link is
  refused for that row with "use its Direct link", so no product shows a broken photo.
- New photos on a live product wait for approval (seller change rules); an unchanged link keeps the stored photo.

## Step 5 — migrations that must be pending (exactly these)
- none

## Step 10 — scheduled commands that must be listed
- none new

## Walk-through
- Seller → Products → Change many in Excel → Download → the photos column shows the links → add a
  `https://i.ibb.co/...png` link to a product → upload → "1 changes waiting for approval" → Admin → Changes to approve.
