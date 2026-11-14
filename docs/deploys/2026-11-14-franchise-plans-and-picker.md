# Release 2026-11-14 — franchise page: every business in its homepage look, fee plans, business picker

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `b7990b12` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | **yes** |
| Version | `VERSION` file bumped to `2026.11.14.7` |

## What's in it
- Top of /franchise: the four fixed tiles are replaced by every business live on the app, in two rows that scroll
  past each other, each drawn like its homepage "Explore" tile (same picture and colour). Stops for people who turned
  motion off. A hero picture uploaded by the Super Admin still wins.
- "Every business, one franchise": each live business is a card with its homepage picture, a Live badge and one line
  on why it grows (Super Admin words, one line per business; no market-size numbers shipped).
- Franchise plans (owner's sales plan): All-in-One ₹5.5 Lakhs (was ₹20 Lakhs), Two-Business ₹3.5 Lakhs (was ₹10
  Lakhs), Add a business later ₹1 Lakh each; royalty 8 %, 3 years; "first 10 franchise sign-ups only".
- "Build your own franchise" picker: tap the businesses you want, the fee updates (two = fee for two, each one more
  = add-on fee, never above the all-businesses fee; one business alone = "talk to our team" until a fee is set).
  Shown only when two or more businesses are live and the three fees are set as numbers.
- Easy payment (₹50,000 advance, balance in 20–25 days after the LOI, refund within 7 days) and the area table
  (Area Franchise / City Franchise).
- "What you get" gains training, marketing material, franchise certificate and area rights, coordinator hiring help.
- Everything above is on Admin → Website & Pages → Franchise page (new groups: Franchise plans and fees, Business
  picker, Payment steps and areas).
- Per-business franchises already exist in the app: Admin → Modules switches each business on or off for one
  franchise. The app does not record which plan a franchise bought or what it paid (that is the agreement).

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_17_100500_franchise_page_plan_lines` — data only. Live has a saved franchise page, whose "What you get" and
  FAQ lists win over the new defaults; this adds the four new "what you get" lines and the "one or two businesses"
  question, and replaces the "How much does a franchise cost?" answer only where it is still the original wording.

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- /franchise top: business tiles scrolling in two rows, with the homepage pictures.
- "Every business, one franchise": white cards with pictures, one per live business.
- Three plan cards with the fees; picker: tap three businesses → ₹4.5 Lakhs, all → ₹5.5 Lakhs.
- Easy payment and area table; "What you get" shows the four new lines; FAQ shows the fee answer.

## Admin settings to do after deploy (owner)
- Admin → Franchise page → "Business picker": set a fee for ONE business if you want it shown (empty = "talk to our team").
- Admin → Franchise page → "Every business": add your own market figures to each business line if you want them.
- Admin → Franchise page → "Franchise plans and fees": change the fees or the offer wording whenever needed.
