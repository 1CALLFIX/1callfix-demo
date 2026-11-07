# Release 2026-11-07 — Voice search + two booking-page fixes

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `2dfc0f6` |
| Full test suite | 4586 tests, 4584 passed; header icon-count test updated for the mic and re-run green (6/6); remaining: known NotificationCenterAuditTest .env failure |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (resources/js/search-bar.js changed) |
| Version | `VERSION` 2026.11.07.9 |

## What's in it (owner 2026-11-07)
- **Voice search.** A 🎤 mic button in the website search box (header, computer and phone). Tap, say "AC repair" /
  "plumber", the words go into the box exactly like typing and the suggestions open. Uses the browser's own free
  speech recognition (Chrome on Android and computers), Indian English. Browsers that cannot listen (iPhone Safari,
  the Android app until a later app update) simply show no mic. Red mic while listening; "Didn't catch that" and
  "Microphone is blocked" messages.
  Super Admin → Site controls → **Voice search** (on by default). Words: Website text → Header (en + te).
- **Order page:** an online booking still waiting for its payment no longer says "Finding you a professional —
  we're contacting professionals" (nothing is sent to professionals until it is paid).
- **Booking summary:** "Pay: Wallet" no longer shows when the wallet is greyed out (not enough balance).

## Step 5 — migrations that must be pending
- none

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Chrome on a computer or Android phone: mic visible at the right of the empty search box; tap → allow microphone →
  say "AC repair" → the words appear and suggestions open. Typing replaces the mic with the clear (x) button.
- Super Admin → Site controls → untick Voice search → Save → mic gone. Tick it again.
