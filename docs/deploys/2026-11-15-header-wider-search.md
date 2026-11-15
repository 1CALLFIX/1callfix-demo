# Release 2026-11-15 — wider header search, Sign in and Cart at the far right

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `afa61bc8` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (new Tailwind classes) |
| Version | `VERSION` file bumped to `2026.11.15` |

## What's in it
- Customer header (`resources/views/components/customer/header.blade.php`), from `sm` up: the search pill is wider
  (`max-w-3xl`, was `max-w-lg`), and Sign in then Cart sit at the far right end of the bar (Amazon / Flipkart pattern).
  Phones keep their current order and layout. Nothing else in the header changed.

## Step 5 — migrations that must be pending (exactly these)
- none

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Computer: search bar fills the space between the brand and the buttons; right side reads Join Us, Book Now, menu,
  Sign in, Cart (cart last). Signed in: no Sign in link, cart still last.
- Phone: header unchanged (logo, location, cart, menu, search row below).

## Admin settings to do after deploy (owner)
- none
