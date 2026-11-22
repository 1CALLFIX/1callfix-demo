# Release 2026-11-22.11 — Super Admin sets the homepage banner size

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `b35bdb0` (2026.11.22.10) |
| Full test suite | passed 5176 of 5176 (local, 2026-11-22); known failures: none |
| Composer | no |
| Migrations | none |
| Front-end build (steps 6–7) | no (the size is an inline style; the admin screen uses the Tailwind CDN) |
| Scheduled commands | none new |
| Version | `VERSION` 2026.11.22.11 |

## What's in it
Owner 2026-11-22: "the banners look very small i mean the image height is so small" … "its better to give super admin
control so that they can be controlled from there".

The HD banners are 1920 × 640 (3:1) and keep their shape, so their height is a third of their width; with 2.3 banners
side by side on a laptop each was ~130 px tall, ~106 px on a phone.

- Admin → **Homepage & sign-up** → new card **🖼️ Homepage top banners: size** (Super Admin, audited), tap chips:
  - 💻 Computers and tablets: Big (1 banner, next peeking) · Large (1½) · Medium (2) · Small (2 and a peek, the
    layout until now, default) · Smallest (3)
  - 📱 Phones: Full width (1 banner) · 1 banner, next peeking (until now, default)
- Nothing changes on deploy until a size is saved. With 2 banners, "and a peek" / "3" show the two side by side.
- Only the homepage top banner strip; business page banners are already one full-width banner.
- At a 1024 px window, Big = 832 × 291 px per banner (was ~400 × 133).

## Step 5 — migrations that must be pending (exactly these)
- none

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Admin → Homepage & sign-up shows the banner size card; pick Big, Save; the homepage banners are big within a minute.

## Admin settings to do after deploy (owner)
1. Pick the computer and phone sizes you like and press Save.
2. For taller banners on phones, upload taller pictures (e.g. 1600 × 800) in Admin → Banners.
