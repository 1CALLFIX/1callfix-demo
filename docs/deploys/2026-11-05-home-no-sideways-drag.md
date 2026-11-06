# Release 2026-11-05 (4) — homepage no longer drags sideways on phones

Use with `docs/DEPLOY_RUNBOOK.md`. On top of `4b82cad`.

| | |
|---|---|
| Live before | `4b82cad` |
| Composer | no |
| Front-end build (steps 6–7) | yes (`overflow-x-clip`) |
| Version | `VERSION` 2026.11.05.4 |

## What's in it
- The one-row category icons ("Home services at your doorstep") made the whole page wider than the phone
  (measured on live: 514px page on a 373px screen), so the page slid sideways and the bottom bar stretched.
  The icons box now stays screen-width (`min-w-0`), and the homepage and every customer page clip any
  sideways overflow (`overflow-x-clip`).

## Step 5 — migrations that must be pending
- none

## Step 10 — scheduled commands
- none new

## Browser checks after deploy (phone)
- Swipe the page left/right: it must not move. Only the rows themselves scroll sideways.
- Bottom bar shows all five items (Home, Services, Shop, Orders, Account) at screen width.
