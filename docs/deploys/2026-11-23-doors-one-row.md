# Release 2026-11-23.1 — homepage doors: small icons in one row, number badges, no heading on phones

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `eb590aa` (2026.11.22.13) |
| Full test suite | passed 5182 of 5182 (local, 2026-11-23); known failures: none |
| Composer | no |
| Migrations | none |
| Front-end build (steps 6–7) | no (styled inline; Alpine comes with Livewire; admin uses the Tailwind CDN) |
| Scheduled commands | none new |
| Version | `VERSION` 2026.11.23.1 |

## What's in it
Owner 2026-11-23 (phone screenshots, Flipkart comparison): more room at the top, smaller module icons, one row.

- Phones: "Explore 1CallFix" and the line under it are no longer shown (the heading stays for screen readers), so the
  businesses sit right under the search bar. Computers keep both.
- The doors are small icons in ONE row everywhere (replaces the 2026-11-22 "rows of 4" grid). Phones: the row swipes
  sideways, about 5½ businesses show and the next one peeks in; no scroll bar shows. Computers: centred, and only
  when more businesses are added than fit (Taxi, Grocery, …) it scrolls, with ‹ › arrows.
- The live line under each name ("46 services to book", "1 shop open now") is gone from view (still read out to screen
  readers and shown as the hover hint). The number now sits as a small badge on the picture, in the business colour,
  only from 10 up by default: today Services 46 and Rentals 31 show it; "1 shop", "1 hotel" do not (they read as an
  empty app at launch). Parcel has a price, not a count: no badge.
- Admin → Homepage sections → "Explore 1CallFix" doors → 🔢 "Show the number badge from [10] up" (1 = always,
  0 = never; audited). The existing "Live number badge" tick still switches all badges off.
- Businesses bar (on scroll): "Second-hand" was cut to "Second-hanc" on phones; the pills are a little tighter so
  every name fits.

## Step 5 — migrations that must be pending (exactly these)
- none

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Phone: no "Explore 1CallFix" heading; one row of small icons under the search bar, swipe sideways, no scroll bar,
  the page itself does not move sideways. Services shows 46 and Rentals 31; Shop / Food / Hotels show no number.
- Computer: heading and line shown, icons in one centred row, no arrows (8 fit).
- Scroll down: the names bar shows all 8 names in full, including "Second-hand".
