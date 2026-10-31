# Release 2026-10-31 — Super-app shell: Telugu, one cart, doors, one search, business cards

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the merge commit that adds this file |
| Live before | `5c23234` (Test shop) |
| Full test suite | see the merge commit; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (new Tailwind classes) |
| Version | `VERSION` file bumped to `2026.10.31.1` |

## What's in it
- **Website text & language** (Admin → Growth): every customer word on the header, bottom bar, location picker,
  cart, search and homepage, in English and Telugu. Telugu is OFF until switched on there (have a native reader check
  the Telugu first). Staff, partner, rider and seller screens stay English.
- **One cart**: `/cart` shows a Services row plus one box per shop, each with its own checkout. `/shop/bag` 301s to
  `/cart` (query kept). The header count includes shop items.
- **"Explore 1CallFix" doors** on the homepage (Services / Parcel / Shop), only where each business is on, with real
  live numbers.
- **One search**: shop items and shops appear in the search box and results page where the shop is on.
- **Business cards** (Paytm/PhonePe-style pattern, own design): one card per service category (its subcategories as
  icons), a Parcel card and a Shop card, each with "View all", one offer line and "More".
- **Admin → Growth → Homepage sections**: page order (move ↑ ↓, on/off) for every homepage section, doors settings,
  and card settings (icons per card, number of category cards, "New" badge days, per card on/off, order, offer line
  English + Telugu). Nothing moves on deploy: the order follows the old position settings until you reorder.
- Parcel form opens on "Schedule" from `/parcel?when=scheduled` (Parcel card icon, only where scheduling is on).
- No migration (settings only).

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: `0`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- `/` (choose a Nellore area): doors row, category icons, then the business cards with "View all".
- `/cart` while empty: one empty cart. Add a shop item (where Commerce is on): it shows in the same cart.
- `/shop/bag` → lands on `/cart`.
- Search box: type a service name → results as before.
- Admin → Growth → Homepage sections and Website text & language both open (Super Admin).

## Admin settings to do after deploy (owner)
- None required. Optional: type offer lines on Homepage sections; reorder sections; switch Telugu on only after a
  native reader has checked Website text & language.
