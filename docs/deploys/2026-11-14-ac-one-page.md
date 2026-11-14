# Release 2026-11-14 — AC one page (Urban Company way) + options never change the price

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `39e34ce6` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | yes (new colours / buttons on the subcategory page) |
| Version | `VERSION` file bumped to `2026.11.14.3` |

## What's in it
- Owner 2026-11-14, the Urban Company way: open Appliance → **Split AC** (or Window AC / Other AC) and every service
  shows on one page in coloured, numbered sections: **1 Service** (the Split AC Service card with its 5 choice
  buttons), **2 Repair & gas** (repair, gas top-up, gas charging), **3 Install & move** (install, un-install,
  shifting). Every item has a big **Add** button; after Add it becomes a − 2 + stepper, and a "View cart" bar stays
  at the bottom on phones. Every item is its own real service at its own price; the cart and checkout are unchanged.
- "All", a search or a sort still show the normal card grid. A subcategory with no sections set (Geyser, Fridge, ...)
  is unchanged until the owner gives its services a section.
- Admin → Services → Edit: new "Section on the page" box with tap buttons for the sections already used.
- Money gap closed: service **Options** never change the price any more (the booking never charged them).
  Customers only see options that do not change the price; the admin Options box has no price field; any old
  priced option shows a red "hidden from customers" badge.
- Data migration: puts the live Split AC / Window AC / Other AC services in the three sections by name (install /
  shift → Install & move; repair / gas → Repair & gas; the rest → Service) and reshuffles only those services' own
  sort numbers so the sections come in that order. Nothing outside AC moves; prices untouched.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_17_100000_add_service_page_section`
- `2026_11_17_100100_ac_page_sections`

Rollback count for `migrate:rollback --step=`: `2` (the AC sort numbers stay reshuffled; harmless)

Before migrating, report any priced options (should be 0):
`php artisan tinker --execute="echo App\Models\ServiceOption::where('price_delta','!=',0)->count();"`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- /nellore → Services → Appliance | AC Repair → tap **Split AC**: sections 1 Service, 2 Repair & gas, 3 Install & move.
- Service section: Split AC Service with General / Jet pump / Foam + jet pump / Chemical wash / Full service; tap
  Chemical wash → ₹1,799 shown; Add → "Added to your cart", stepper shows 1, bottom bar "1 in your cart".
- Add Gas Top-Up too → View cart → both lines at their own prices, one visit.
- Tap **Window AC**: its services in sections. Tap **All**: normal cards as before.
- Admin → Services → Edit Split AC Repair: "Section on the page" shows "Repair & gas".

## Test login / walk-through
- No new app or role: use the existing customer test login from `php artisan test-accounts`.

## Admin settings to do after deploy (owner)
- Optional: check each AC service's section (Admin → Services → Edit → Section on the page).
- Later: give Geyser / Fridge / Washing Machine services sections the same way to get the same one page.
