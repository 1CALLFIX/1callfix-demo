# Release 2026-11-08 — voice search inside the Android apps

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `0d62ebf` |
| Full test suite | see commit message; known failure only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (resources/js/search-bar.js) |
| Version | `VERSION` file bumped to `2026.11.08.3` |
| Android app build | yes, on the build PC (`mobile/README.md` → Every build); new add-on `@capacitor-community/speech-recognition` 7.0.1 + microphone permission |

## What's in it
- The search box mic now works inside the Android apps: the first tap asks "Allow microphone?", Google's own
  "Speak now" box listens, and the words go into the search box like typing (Indian English).
- Apps built before this release keep showing no mic (they lack the add-on), so nothing breaks before people update.
- Browsers (Chrome on phones, computers) are unchanged.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Checks after deploy
- Website: the mic in Chrome still works as before.
- New customer app build (internal testing): mic shows, microphone prompt, "AC repair" lands in the box.

## Admin settings to do after deploy (owner)
- None required (voice search switch: Super Admin → Search box, already on).
