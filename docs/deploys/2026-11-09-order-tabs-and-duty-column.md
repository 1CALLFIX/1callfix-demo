# Release 2026-11-09 — restaurant order tabs + true "taking jobs" column

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `5cfbbe0` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (new tab and step classes) |
| Version | `VERSION` 2026.11.09.7 |

## What's in it
- **Restaurant / shop Orders: one tab per step** (owner: "only In progress; Ready and Handed over are not there; where
  does the owner confirm cooking / ready?"). Tabs: New · Cooking (Packing for shops) · Ready · Handed over · Completed ·
  Cancelled · Problems, each with its count. Every order shows a progress line (Accepted → Cooking → Ready → Handed
  over → Delivered; pickup orders end at Collected). Buttons: Accept order → Start cooking → Mark ready → (rider's code)
  Handed over. After each button the screen follows the order to its new tab. The old "In progress" link opens Cooking.
- **Admin Partners / Providers "Online" column** (owner: "he went offline in the app but here it still shows online").
  "Online" used to mean "used the app in the last 5 minutes"; the real switch was the small "Off duty" tag. Now the
  first line is what dispatch uses and changes at once: **Taking jobs** (switch on + location within
  `provider.location_stale_after_minutes`, the same rule offers use), **On duty · no location** (switch on but the phone
  has gone quiet: no offers reach them), **Off duty**. Second line: "App open now" / "Seen 16m ago". Customer and user
  lists unchanged. Riders were always rung only when on duty with a fresh location; this was a display problem.

- **Hand-over only to a rider at the counter** (owner: the rider's screen said "tell the pickup code" with no code on it).
  The restaurant had tapped Handed over before the rider tapped "Reached restaurant". Now "Handed over" works only
  after the rider has reached ("Rider not here yet. Hand over when he arrives and tells the code."), and the rider's
  message carries the code itself: "Tell code 1240 at the counter."

- **"Reached restaurant" only within 150 m** (owner: "how near must the rider be?"). The phone's GPS at the tap is
  checked on the server against the restaurant's / shop's map pin. Farther: "You're 1.2 km away. Tap when you reach.";
  location off: "Turn on location to continue." A store without a map pin is not checked. Super Admin setting:
  Shop Settings → Delivery → **Reached distance** (default 150 m, 50–2000). If one restaurant is often refused, fix its
  map pin rather than widening the distance for everyone.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Restaurant test login → Orders: tabs New / Cooking / Ready / Handed over / Completed; an order's progress line.
- Admin → Partners: Mohammed Anees with the switch off shows "Off duty"; Test Rider shows "On duty · no location".
