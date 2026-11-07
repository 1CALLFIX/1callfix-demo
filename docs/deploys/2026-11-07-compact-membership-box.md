# Release 2026-11-07 — compact membership box on the homepage

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `b21bd1e` |
| Full test suite | 4572 tests, 4571 passed; known failure only: NotificationCenterAuditTest (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (new card styles) |
| Version | `VERSION` 2026.11.07.6 |

## What's in it (owner 2026-11-07: "more beautiful and smaller; another plan must fit beside Prime Silver")
- The homepage membership box is about half the height on phones, in the Urban Company Plus / Swiggy One /
  Zomato Gold style: one slim heading line (gold "Members save more", "1CallFix membership", the line under it), then
  compact dark plan cards with a gold crown, the price on the right, "Benefits worth" chip, one-line benefits and a
  gold "See all benefits & join" button pinned to the bottom of the card.
- Up to 3 live plans (cheapest first) sit side by side: two on a computer, and on a phone the next card peeks in to
  swipe. One plan: full width on phones, compact on computers.
- Same Super Admin controls as before (how many benefits to list, the worth chip); every word is still Website text.

## Step 5 — migrations that must be pending (exactly these)
- none

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Homepage on a phone: the membership box is the new compact dark card with the gold button.
- Homepage on a computer: the card is compact (not full width) and the page does not scroll sideways.
