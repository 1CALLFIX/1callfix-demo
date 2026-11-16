# Release 2026-11-16 (4) — one account control with hover menu, Book Now off, location box, search across modules

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `d058e3f0` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (new Tailwind classes: `max-w-[20rem]`, hover states) |
| Version | `VERSION` file bumped to `2026.11.16.4` |

## What's in it
- Header: "Sign in ▾" (or the customer's avatar + first name) is ONE control; the separate Sign in link and the
  hamburger are gone on desktop (phones keep the round button). Resting the mouse on it opens the menu, leaving closes it.
  Signed out, the menu starts with "New customer? Sign up" and a big Sign in button (no repeated Sign in row).
- "Book Now" is hidden by default (it only meant services).
- Location is its own box beside the search, showing a short "Area, City" (e.g. "Mulapet, Nellore"); it stretches up to
  20rem for long names, the full text is the tooltip.
- Search (box + results page) also covers restaurants/dishes, events, function halls and homes for rent, each only where
  that module is live in the visitor's city.
- Hover feedback on menu rows and header buttons.
- Super Admin → Site controls → "Website header": switches for hover-open and Book Now.
- New Website text keys (with Telugu): `search.restaurants`, `search.events`, `search.halls`, `search.homes`,
  `header.new_customer`, `header.sign_up`, `location.deliver_to`.

## Step 5 — migrations that must be pending (exactly these)
- none

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Signed out, desktop: one "Sign in" in the bar; hover opens the menu with Sign up + Sign in button; moving away closes it.
- Signed in: avatar + first name; hover shows My Orders etc.
- Header shows no "Book Now". Location box reads "Area, City" and is separate from the search.
- Type a restaurant, hall or event name in search: it appears under its own group (where that module is live).

## Admin settings after deploy (owner)
- Site controls → Website header: leave hover on; turn "Book Now" on only if wanted.
