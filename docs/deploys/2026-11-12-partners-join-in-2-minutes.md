# Release 2026-11-12 — Partners: join in 2 minutes (part A)

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `a8e3bd0` |
| Full test suite | see the deploy report; known failures only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (new sign-in and "Finish joining" screens) |
| Version | `VERSION` file bumped to `2026.11.12.4` |

## What's in it
Owner 2026-11-12: "every module whether it's a shop, restaurant, hotel, provider ... should feel very easy to join apart
from all of the aggregators". Part A of the plan, for every partner (service, rider, shop, restaurant, hotel):
- **Apply in about 2 minutes:** mobile code, name, business name, address. No password and no documents by default.
- **Signed straight in** after applying (the number is proven by its code) → **"Finish joining"** (`/partner/join`):
  1 applied ✓, 2 documents (each uploads as soon as it is picked, phone camera works), 3 set-up links (rooms, menu,
  products, dashboard), 4 approval. Approval still needs every required document (unchanged admin review).
- **Every partner sign-in opens on "a code sent to your mobile"** (partner / hotel, seller / restaurant, rider);
  `?with=password` (or "Sign in with a password instead") is the password form.
- Portal homes (provider, seller, hotel, rider) show "Finish joining: upload N documents" until they are all in.
- Header "Become a partner" button now also shows when only a hotel is left to join.
- Super Admin → Homepage & sign-up → **"Partner sign-up: join in 2 minutes"**: "Ask for a password at sign-up" (off)
  and "Documents on the sign-up page" per partner type (all off).
- New partners without a password: stored as no password (never a hash of an empty one).

## Step 5 — migrations that must be pending (exactly these)
- none

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- /provider/login, /seller/login, /rider/login open on "Mobile number → Send code".
- /partners → a role → the sign-up page: no password, no document fields, "No documents needed to apply".

## Admin settings to do after deploy (owner)
- Optional: Admin → Homepage & sign-up → "Partner sign-up: join in 2 minutes" (defaults are the recommended ones).
