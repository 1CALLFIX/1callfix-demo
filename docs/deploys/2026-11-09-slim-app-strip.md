# Release 2026-11-09 — slim "Get the app" strip + footer app badges

Use with `docs/DEPLOY_RUNBOOK.md`. Ships together with `2026-11-09-cloudflare-layer1.md` (same deploy).

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `783ebbd` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (new layout classes) |
| Version | `VERSION` 2026.11.09.3 |

## What's in it
- Owner: "the app section is very large, make it like Swiggy, Zomato, Amazon, Flipkart, Urban Company". The homepage
  "Get the app" is now one slim strip: logo, heading, one line, the Google Play badge, a QR code on computers, and the
  iPhone "Add to Home Screen" line. About a fifth of the old height. The full version stays on the `/app` page.
- Owner: "in footer no app install shortcuts are visible". The footer now has a "Get the app" heading under the logo with
  the Google Play badge and an iPhone button ("Use on iPhone" → /app steps, or the iPhone app if a link is set), shown on
  phones too. Never inside our Android apps (unchanged).
- New Website text: `get_app.footer_iphone` (en + te).

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Homepage on a computer: the slim strip with Play badge and QR, above the FAQ.
- Homepage on a phone: strip fits the screen; footer shows "Get the app" + Google Play + "Use on iPhone".
- `/app`: the full big section, unchanged.
- In the customer Android app: no strip, no footer badges.

## Admin settings to do after deploy (owner)
- none (texts editable in Website text → Get the app section).
