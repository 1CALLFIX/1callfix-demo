# Release 2026-11-21.1 — "Words are on the picture" switch for banners

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `2d27f84` (2026.11.20.3 referrals page minimum line) |
| Full test suite | 5047/5047 passed (2026-11-21, final code), no known failures |
| Composer | no (`composer.lock` unchanged) |
| Front-end build (steps 6–7) | no (the new admin label uses `ml-6`, `text-xs`, `text-gray-400`, `h-[38px]`, all already in the live CSS on this screen) |
| Version | `VERSION` 2026.11.21.1 |

## What's in it
### Banners: "Words are on the picture" (owner 2026-11-21, HD module banners)
- Admin → Banners, add and edit: a new tick box **Words are on the picture**. Ticked, the site no longer prints the
  banner's title (with its dark fade and "View") over the bottom of the picture. Use it for the new HD banners, which
  carry their own headline, Telugu line and button.
- The title is still required and still used: it names the link for screen readers and shows in the admin list.
- Every existing banner stays as it is (the box is off by default).
- `/api/banners` now also returns `text_on_image` (true/false) for the apps.
- New column `banners.text_on_image` (boolean, default false).

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_21_100000_add_text_on_image_to_banners`

Rollback count for `migrate:rollback --step=`: 1

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Admin → Banners: the add row and the edit window show "Words are on the picture".
- Upload one HD banner with the box ticked: on the homepage (or its module page) the picture shows with no title
  printed over it.

## Admin settings to do after deploy (owner)
- Upload the HD banners from `D:\D-Downloads\1CallFix Super App\Banners HD 2026-11-21\` (list and suggested
  "Where it shows" in that folder's README), ticking **Words are on the picture** on each.
