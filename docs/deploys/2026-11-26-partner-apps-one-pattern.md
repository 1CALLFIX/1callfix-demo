# Release 2026-11-26 — One pattern in every partner app: tabs and app colours

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `b0434cd` |
| Full test suite | passed 5,258 of 5,258; known failures only: none |
| Composer | composer.lock changed: no |
| Front-end build (steps 6–7) | no — every class the shared tab uses is already in the live build (`app-CNS-_MDI.css`, checked 2026-11-26; orange uses `border-transparent` because `border-orange-500` is not in it) |
| Version | `VERSION` file bumped to `2026.11.26.1` |

## What's in it
- Owner 2026-11-26: "make sure it must be easy" and "if all the apps follow the same pattern it is easier to find
  any issue". One shared tab, `<x-partner.tab>`: 44 px, its own colour and icon per step, count badge, and past
  work always the last, green 🕘 tab.
- Used on: service partner Jobs (🔔 New offers | 🕘 Job history), shop and restaurant Orders (🆕 New, 📦/🍳 Packing/
  Cooking, 🛎️ Ready, 🛵 Handed over, 🕘 Completed, ✖️ Cancelled, ↩️/⚠️ Returns/Problems), hotel Bookings (⏳ To accept,
  🧳 Arriving, 🛏️ Staying, 🕘 Past), function hall Bookings (📅 Upcoming, 🕘 Past), house owner Visits (🆕 New,
  ✅ Accepted, 🕘 Past). Tabs wrap to a second line instead of hiding off the edge of the phone.
- `tests/Feature/Partners/PartnerTabsPatternTest.php` fails if a work screen goes back to a hand-made tab row.
- CLAUDE.md ease-of-use rule: every future partner app uses the same pattern from day one.
- Owner 2026-11-26: a partner switching from the Partner app to the Rider app saw the same screen ("You are
  offline") and could not tell which app he was in; online is separate in each. Every partner app now has its own
  colour and icon (`<x-partner.app-badge>`): a coloured strip along the top, a badge next to 1CallFix, and the phone
  bar colour (theme-color). 🛠️ Partner blue, 🛵 Rider orange, 🏪 Shop green, 🍽️ Restaurant red, 🏨 Hotel violet,
  🏠 Rental teal, 🎉 Events pink. The Partner and Rider online cards say "Service Partner app" / "Delivery Rider app".
  `tests/Feature/Partners/PartnerAppIdentityTest.php` checks every test login sees only its own app.
- Riders keep History in the bottom bar. Taxi drivers, fleet owners and vehicle rental owners have no work screen
  yet; they get this pattern when built.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- test.provider: Jobs shows 🔔 New offers | 🕘 Job history.
- test.seller / test.restaurant: Orders tabs big and coloured, 🕘 Completed opens past orders.
- test.hotel: Bookings → 🕘 Past. test.hall: Bookings → 🕘 Past.
- test.provider then test.rider: blue 🛠️ Partner vs orange 🛵 Rider header, and the app name on the online card.

## Admin settings to do after deploy (owner)
- none
