# Release 2026-11-09 — Cloudflare Layer 1 (real visitor address)

Use with `docs/DEPLOY_RUNBOOK.md`. The Cloudflare switch itself is `docs/CLOUDFLARE_SETUP.md`, done AFTER this release is live.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `783ebbd` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | no (no front-end change; skip steps 6–7) |
| Version | `VERSION` 2026.11.09.2 |

## What's in it
- The site trusts Cloudflare's published addresses (`App\Support\Cloudflare::IP_RANGES`), and only those, to say who the
  real visitor is and that the visit was https. Without it, once Cloudflare is in front every customer would look like a
  handful of Cloudflare addresses and one person's failed sign-ins / OTP requests would lock out everybody
  (38 places use the visitor's address, 16 rate limits).
- Before Cloudflare is switched on this changes nothing: visitors reach the server directly and keep their own address;
  anyone sending a fake forwarded address is ignored (tested).

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Home, sign-in with password, a Food restaurant page, admin login: open normally.
- `tail storage/logs/laravel.log`: no new errors.

## Admin settings to do after deploy (owner)
- none. Next: `docs/CLOUDFLARE_SETUP.md`.

## If Cloudflare's address list ever changes
Cloudflare announces changes in advance at https://www.cloudflare.com/ips/ . Update `App\Support\Cloudflare::IP_RANGES`,
run `tests/Feature/CloudflareTrustedProxyTest.php`, release.
