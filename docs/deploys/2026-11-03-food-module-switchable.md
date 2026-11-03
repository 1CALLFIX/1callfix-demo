# Release 2026-11-03 — Food can be switched on in Admin → Modules

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `4a19fac` (Food F1–F5 + seller change rules + product import, VERSION 2026.11.03.4) |
| Full test suite | 2026-11-03 on 4a19fac + this fix, folder by folder: 4462 tests, all pass except the known `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env, unrelated) |
| Composer | no |
| Front-end build (steps 6–7) | no (no views or JS changed) |
| Version | `2026.11.03.5` |

## What's in it
Found by the owner right after the Food deploy: Admin → Modules listed Food Delivery as "Not yet built"
(`modules.is_implemented = false`), so it could not be switched on. One data migration marks it built, the same step
E-commerce got on 2026-10-29. **Nothing switches on**: Food stays Inactive everywhere until it is activated on the
Modules screen. Grocery and pharmacy stay "Not yet built".

## Step 5 — migrations that must be pending (exactly this)
- `2026_11_03_130000_mark_food_module_implemented`

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Admin → Modules → Nellore: Food Delivery shows "Implemented" and "Inactive", with an "Activate here" link.
