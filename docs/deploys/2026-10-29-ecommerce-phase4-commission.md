# Release 2026-10-29 — E-commerce Phase 4 (shop commission control)

Use with `docs/DEPLOY_RUNBOOK.md`. Carries Phases 1–3 if they are not live yet (see their notes).

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | check `git log -1` on the server |
| Full test suite | see the merge commit; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | yes |
| Version | `VERSION` file bumped to `2026.10.29.2` |

## What's in it
Owner decision 2026-10-24: commission varies by category, vendor (shop), franchise and city, set by Super Admin
and franchise admins.
- Admin → Shop Commission: rules of shop / category / franchise / city (blank = any) → platform fee %. The most
  specific wins: shop + category › shop › category in a franchise › category in a city › category › franchise ›
  city; a sub-category inherits its parent's rule. No rule = the existing hierarchy (negotiated seller deal →
  franchise fee → global default), so nothing changes until a rule is added. A mixed order pays the line-weighted
  fee. Delivery fees are untouched. "Which fee applies?" preview. Audit-logged.
- Permission `marketplace_commission.manage` (Super Admin only by default): HQ grants set any rule; franchise grants
  only for their own franchise and shops, between Shop Settings → Commission bounds (blank = franchises cannot set).
- Not built: demand-based (surge) commission — needs an owner decision on the signal and caps.

## Step 5 — migrations that must be pending (exactly these)
- `2026_10_29_200000_create_marketplace_commission_rules_table` (also seeds the permission)
- plus the Phase 1 and Phase 3 migrations if they ship together

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Admin → Shop Commission opens with "No rules yet"; preview for Test Shop shows the current franchise fee.

## Admin settings to do after deploy (owner)
- Optional: Shop Settings → Commission → franchise min / max to let franchise admins set rules.
