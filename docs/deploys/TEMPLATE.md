# Release YYYY-MM-DD — short name

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `<hash currently on the server>` |
| Full test suite | `<passed / failed>` of `<total>`; known failures only: `<list or "none">` |
| Composer | `<composer.lock changed: yes/no>` |
| Front-end build (steps 6–7) | `<yes/no>` |
| Version | `VERSION` file bumped to `<yyyy.mm.dd>` (add `.2`, `.3` for a second release the same day); shown on logins, side menus, footer |

## What's in it
- …

## Step 5 — migrations that must be pending (exactly these)
- `…`

Rollback count for `migrate:rollback --step=`: `<n>`

## Step 10 — scheduled commands that must be listed
- `…`

## Browser checks after deploy
- …

## Admin settings to do after deploy (owner)
- …
