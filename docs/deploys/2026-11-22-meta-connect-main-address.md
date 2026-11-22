# Release 2026-11-22.4 — Facebook Connect always uses the main address; auto-posts every 5 minutes

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `47c43f9` (2026.11.22.3 post any item / franchise posts / share your business) |
| Composer | no |
| Migrations | none |
| Front-end build | no |
| Scheduled commands | none new; `marketing:posts-due` now every 5 minutes (was 15) |
| Version | `VERSION` 2026.11.22.4 |

## What's in it
Owner 2026-11-22: "still getting url blocked while saving the credentials and connecting to fb insta" and
"scheduled at 11:18 AM today but nothing was posted".

- **Connect Facebook & Instagram** always sends Facebook `https://1callfix.com/admin/marketing/meta/callback`
  (APP_URL), the address saved in the Meta app. The site also answers on `http://` and `www.`; pressing Connect there
  first moves to `https://1callfix.com` (same host both ways, so the login and the security check survive).
  Meta kept dropping the `www.` redirect URI, so one address is the reliable fix.
- `marketing:posts-due` runs every 5 minutes, so a Facebook / Instagram post goes out at most 5 minutes after its
  time. The morning reminder and 18:00 nudge stay once a day (cache guards).

## Step 5 — migrations that must be pending
None.

## Step 10 — checks
- `php artisan schedule:list | grep marketing` shows `*/5`.
- Admin → Marketing → Social posts: the card's return address shows `https://1callfix.com/admin/marketing/meta/callback`.
