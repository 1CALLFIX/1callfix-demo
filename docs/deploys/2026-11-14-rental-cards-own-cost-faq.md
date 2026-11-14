# Release 2026-11-14 — two rental cards on the homepage, no half-empty card row, franchise "on your own" costs

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `20df38db` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | **yes** |
| Version | `VERSION` file bumped to `2026.11.14.8` |

## What's in it
- Homepage business cards: two new cards, "Function halls" and "Property for rent" (owner: "the rentals both
  categories"), each shown where that rental kind is live in the visitor's city and has something to book. On/off,
  order and the line under the heading: Admin → Homepage sections, like every other card.
- Homepage business cards: on a computer the row length now follows the number of cards so no row ends half empty
  (8 or 12 cards = 4 across, 6 or 9 = 3 across, 5 or 10 = 5 across); a short last row that cannot be avoided sits in
  the middle. Phones (2 across) and tablets (3 across) as before.
- /franchise numbers strip: "businesses live on one app" counts every live business (it counted four fixed ones).
- /franchise: new section "What it costs to start each business on your own" (owner's figures): set-up cost, time
  to profit and risks for Home Repair, Food Delivery, E-commerce and Parcel Delivery (owner's slides) plus Hotel &
  Stay Booking, Rentals and Events & Tickets (owner asked for them; figures are estimates the owner confirmed by
  deploying, editable); a total line (₹66–96 Lakhs on your own vs ₹5.5 Lakhs All-in-One); the five risks of starting from scratch; small print that these are estimates.
  All on Admin → Franchise page → "What it costs on your own".
- /franchise: questions in two columns on a computer.
- NOT added (owner's slides 7 and 10): the "franchise cost" column (₹20 Lakhs / ₹10 Lakhs, different from the plans
  now on the page) and the reach figures (10+ cities, 500,000+ users, 30 % growth), which the app's own live numbers
  on the same page do not support. International (USD) figures left out: the page is for Indian cities.

## Step 5 — migrations that must be pending (exactly these)
- none

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Homepage on a computer: business cards include Function halls and Property for rent; every row full (or the short
  last row in the middle).
- /franchise: numbers strip shows 7 businesses; "on your own" section with seven cards (4 + 3 in the middle) and the total line; FAQ in
  two columns.

## Admin settings to do after deploy (owner)
- Admin → Franchise page → "What it costs on your own": change any figure or wording.
