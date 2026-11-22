# Release 2026-11-22.2 — Social posts go out on Facebook & Instagram by themselves

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `4473c7b` (2026.11.22.1 Settings tabs / App Upgrade) |
| Composer | no |
| Migrations | none (Page token in the existing encrypted `social_media_links.access_token`; ids in settings) |
| Front-end build | no (admin Tailwind CDN) |
| Scheduled commands | none new; `marketing:posts-due` (every 15 min) now also posts |
| Config | optional `META_GRAPH_VERSION` (default `v27.0`) |
| Version | `VERSION` 2026.11.22.2 |

## What's in it
Owner 2026-11-22: "how come from admin panel it will directly be posted on all the social media handles".

- Admin → Marketing → Social posts → **Post automatically to Facebook & Instagram** (Super Admin only):
  1. the company's Meta app ID + secret (secret stored encrypted, write-only, never logged);
  2. **Connect**: approve once on Facebook (Development-mode app: only its admins can connect); one Page connects
     straight away, several → pick one; the Page token (from a long-lived login, does not expire) stored encrypted;
  3. automatic posting on / off.
- Scheduled posts whose time has come go out on Facebook (photo + caption + tracked link) and Instagram (JPEG copy
  padded to 1.91:1 / 4:5, never cropped) via `marketing:posts-due`. Once per platform: claimed before the call, a
  failure is shown with the reason and **Try again**, never retried by itself. Expired login → "Connect again".
- Each post card: "Posts by itself at 10:00 AM", **Post now**, failures in red. Google Business and WhatsApp Status
  stay one tap. The morning reminder only counts what still needs a person.

## After deploy (owner + Claude in Chrome)
1. Meta app **1CALLFix** (445952729385294) → Facebook Login → Settings → Valid OAuth Redirect URIs:
   `https://1callfix.com/admin/marketing/meta/callback` (use the admin's real host if different).
2. Admin → Social posts: type App ID, paste App secret (Meta app → App settings → Basic → Show), Save, Connect.
3. Post one test with **Post now**; delete it from the Page if not wanted.
