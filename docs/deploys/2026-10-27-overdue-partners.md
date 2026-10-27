# Release 2026-10-27 — Overdue partners stand out

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `e4da33e` |
| Full test suite | 4112 of 4113 pass; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (new red row, badge and dot classes) |
| Version | `VERSION` file bumped to `2026.10.27.1` |

## What's in it
Owner request 2026-10-27: a partner waiting past 48 h only turned the hours number red, which was easy to miss.
- **Red row + pulsing "Overdue" badge** on Admin → Partners (People and Businesses) and the Providers list, on
  Needs review, for every partner waiting past the Overdue hours. The pulse stops for users who turn off motion.
- **Needs review (1 · 1 overdue)** on the list tab, and a red "N overdue" pill next to People / Businesses.
- **Red dot on Partners in the admin menu** on every admin page (hover says "Partners — N overdue").
- **Admin → Homepage & sign-up → Partner review** (Super Admin only, audit-logged): "Wait turns amber after (hours)"
  default 24, "Overdue (red) after (hours)" default 48, 1–720; red can never be below amber.
- The wait is counted from the partner's oldest document still waiting (as before). Every count stays inside the
  user's own area: a franchise owner sees only their city's overdue partners.
- Unchanged: review, approve and reject, document approval, the /partners join flow, payments and approval rules.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: `0`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Admin → Partners → Businesses: Mohammed Anees (Nellore, waiting over 48 h) has a red row, a pulsing "Overdue"
  badge, the tab reads "Needs review (1 · 1 overdue)", the Businesses tab shows "1 overdue".
- The menu shows a red dot on Partners from any admin page.
- Admin → Homepage & sign-up → Partner review shows 24 and 48.
- Test login: `test.franchise@1callfix.test` (from `php artisan test-accounts`) sees the same marks for Nellore only.

## Admin settings to do after deploy (owner)
- Optional: change the amber / Overdue hours.
