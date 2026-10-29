# Release 2026-10-29 — Commerce can be switched on (Modules)

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the merge commit that adds this file |
| Live before | `c7635ac` (e-commerce phases 1–5 + coupons) |
| Full test suite | see the merge commit; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | `no` (no views or JS changed) |
| Version | `VERSION` file bumped to `2026.10.29.5` |

## What's in it
Found in the live look-through after the e-commerce deploy: Admin → Modules listed E-commerce as "Not yet built"
(`modules.is_implemented = false`), so the shop could never be switched on. One data migration marks it built — the
same step parcel got on 2026-10-23. **Nothing switches on**: E-commerce stays Inactive everywhere until you activate
it on the Modules screen. Food, grocery and pharmacy stay "Not yet built".

## Step 5 — migrations that must be pending (exactly this)
- `2026_10_29_500000_mark_commerce_module_implemented`

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Admin → Modules → Nellore: E-commerce shows "Implemented" and "Inactive", with an "Activate here" link.
  Do not click it until the launch settings are done.
- `/nellore/shop` still 404.
