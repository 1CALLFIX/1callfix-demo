# Release 2026-11-14 — homepage the Amazon way + a stronger franchise page

Use with `docs/DEPLOY_RUNBOOK.md`. Deploy AFTER (or together with) `2026-11-14-photos-and-appliance-pages.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `0443f317` (photos release `00a11a32` pushed, not yet deployed) |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | **yes** |
| Version | `VERSION` file bumped to `2026.11.14.6` |

## What's in it
- Homepage business cards, the Amazon way (owner 2026-11-14): every card the same size, 2 across on a phone, 3 on a
  tablet, 4 on a computer; the heading with an arrow is the one link (no more "View all" + "More" on every card), a
  short coloured line under it, then a 2 x 2 photo grid (1-3 items fill the same square). Events keep a live
  countdown badge on the poster.
- "Why choose 1CallFix": each promise its own colour, big icon and number; 2 across on a phone.
- "Everything local, in one app": same width as the sections around it; the empty middle shows the businesses in the app.
- Footer: social icons in brand colours, smaller, so WhatsApp no longer drops to a second line.
- Franchise page (compared with a one-business franchise page): colourful "why" tiles; "Every business, one
  franchise" band listing the businesses live on the app (from the module switches); a comparison table; an earnings
  calculator (hidden until the Super Admin sets the share %); an Apply + WhatsApp bar at the bottom on phones. Every
  word is on Admin → Franchise page.

## Step 5 — migrations that must be pending (exactly these)
- none in this release (the photos release's two migrations, if it is deployed in the same run)

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Homepage on a computer: business cards 4 across, same size, one arrow each; on a phone 2 across.
- "Why choose 1CallFix" in four colours; the app strip lines up with the sections; footer icons in one row.
- /franchise: Every business band, comparison table, phone bar at the bottom on a phone.

## Admin settings to do after deploy (owner)
- Admin → Franchise page → "Earnings calculator": enter your share % to show the calculator (empty = hidden).
- Admin → Franchise page → "How you earn and investment": add the investment figures when ready (still hidden).
