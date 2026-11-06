# Release 2026-11-07 — New look, Phase 1: a colour for each business

Use with `docs/DEPLOY_RUNBOOK.md`. Ships in the same deploy as `2026-11-07-like-and-share-on-photos.md` (both on
`main`; live before is `d63d6b2`).

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `d63d6b2` |
| Full test suite | 4540 passed of 4541 on 1286928; known failures only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` |
| Composer | no |
| Front-end build (steps 6–7) | yes |
| Version | `VERSION` 2026.11.07.2 |

## What's in it (owner "Go" 2026-11-07)
- **A colour for each business:** Services blue #2563EB, Food red #E23744, Shop green #16A34A, Parcel purple
  #7C3AED. Shown as a strip under the header on that business's pages, on its home card (tinted picture box and a
  bar along the bottom), on the food Add buttons, the shop's category chips and the shop name on a product.
  1CallFix blue stays on the main buttons (Book now, Buy now, Pay). Super Admin → Homepage & sign-up → Module colours.
- **Photo first on phones:** service, product and restaurant pages start with the photo edge to edge under the
  header; on a service the title card overlaps the photo. Breadcrumbs show from tablet width up.
- **♡ on every list card:** service cards, product cards, shop cards and restaurant cards (outside the card's link).
- **One rating look:** a small green chip "4.3 ★" everywhere.
- Page speed checked: 1–2 more database reads per page (the module colour), same render time.

## Step 5 — migrations that must be pending
- none from this release (the like-and-share release brings `2026_11_07_100000_create_saved_items_table`)

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy (phone width)
- A service page: blue strip under the header, photo edge to edge, ♡ + Share on it, title card over the photo.
- Food home and Test Restaurant: red strip, red Add buttons, ♡ on each restaurant card.
- Shop home and Test Shop: green strip, ♡ on each product card and shop card.
- Homepage: each business card in its own colour.
- Tail of storage/logs/laravel.log: no new errors.
