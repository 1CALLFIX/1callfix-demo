# Release 2026-11-22.12 — slogan in the homepage "Get the app" strip

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `a53d1db` (2026.11.22.11) |
| Full test suite | passed 5178 of 5178 (local, 2026-11-22); known failures: none |
| Composer | no |
| Migrations | none |
| Front-end build (steps 6–7) | no (the slogan is styled inline; the admin screen uses the Tailwind CDN) |
| Scheduled commands | none new |
| Version | `VERSION` 2026.11.22.12 |

## What's in it
Owner 2026-11-22: in the strip near the bottom of the homepage (logo, Google Play, QR code), "let me insert any slogan
motive words … 'why search here & there just 1CallFix' next line 'haa… one call … does it all' … should be editable by
backend".

- The middle of the strip now shows a two-line slogan instead of the four business names:
  - "Why search here and there? Just 1CallFix!"
  - **"One call… does it all."** (big, blue → purple → orange)
  - Telugu: "అక్కడా ఇక్కడా ఎందుకు వెతకడం? 1CallFix ఉందిగా!" / "ఒక్క కాల్… అన్నీ చేసేస్తుంది."
- Admin → **Homepage sections** → new card **📲 Get the app strip: the middle** (Super Admin, audited): tap
  💬 Slogan or 🏷️ Business names; edit both lines in English and Telugu (up to 80 letters, empty = built-in words).
  The lines are also on Website text & language → Get the app section (`get_app.slogan1`, `get_app.slogan2`).
- Shows on deploy (default Slogan). The /app page is unchanged.

## Step 5 — migrations that must be pending (exactly these)
- none

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Homepage, bottom strip: the slogan in the middle on a computer and on a phone.
- Admin → Homepage sections shows the new card with the four lines filled in.
