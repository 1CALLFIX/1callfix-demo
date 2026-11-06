# Release 2026-11-06 — Share on every public page

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `2595a13` |
| Full test suite | run at deploy time; known failures only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` |
| Composer | no |
| Front-end build (steps 6–7) | yes |
| Version | `VERSION` 2026.11.06.2 |

## What's in it (owner rule 2026-11-06: everything public can be shared)
- Share button on: service, service category, all categories, all services/offers, city pages, shop home, shop,
  product, food home, restaurant, **every dish** (small icon; the link jumps to that dish), parcel, parcel packs,
  membership plan.
- Android apps: the phone's own share sheet (`@capacitor/share` 8.0.3 added to `mobile/package.json`), from the app
  build of 2026-11-06 on. Older app builds and computers: WhatsApp and Copy link. Phone browsers: their own share sheet.
- Words in Super Admin → Website text, new group "Share button" (`share.*`, English + Telugu).
- Hotel, rental and taxi have no customer pages yet; the rule in CLAUDE.md and `ShareButtonTest` make their pages
  carry Share when they are built.

## Step 5 — migrations that must be pending
- none

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- A service page, a product page and a restaurant page show "Share"; on a computer it opens WhatsApp / Copy link.
- Restaurant page: each dish has a small share icon.
- Tail of storage/logs/laravel.log: no new errors.

## App build (owner, same day)
- `git pull`, `npm ci`, `npm run sync`, then build all three apps. In the new apps, Share opens the phone's share sheet.
