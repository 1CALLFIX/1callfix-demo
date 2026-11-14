# Release 2026-11-14 — One Rental + hall details

Use with `docs/DEPLOY_RUNBOOK.md`. Goes out with (or after) the Function halls R1–R4 notes of 2026-11-13.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `<hash currently on the server>` |
| Full test suite | see the merge commit message |
| Composer | no |
| Front-end build (steps 6–7) | yes (new Tailwind classes on the hall page, editor, availability calendar, Rentals page, Homepage sections) |
| Version | `VERSION` bumped on merge |

## What's in it
- **One Rental.** Admin → Modules has one "Rental" row (the "Rental · Function halls" row is folded into it: every
  place it was on, Rental is now on). Rental Settings (was Hall Settings) starts with **Rental kinds**: Function halls
  (on), Properties (on), Vehicles (off), Equipment (off), per global / franchise / zone. A kind is live only where
  Rental is on and the kind is on. The vehicle / equipment partner card on /partners hides while both are off.
- **One Rentals door** on the homepage and city page → `/{city}/rentals`, a page with a coloured tile per live kind
  and the first halls. Properties, Vehicles and Equipment have no customer pages yet, so they do not show there.
- **Door names editable**: Admin → Homepage sections → each door's name and line, English and Telugu.
- **Settings lists visible**: list settings (facilities, kinds, rules, …) show the real list instead of "Not configured".
- **Hall facilities in sections** (Parking and arrival incl. VIP car entry, Bride and groom incl. grand entry, Food,
  Comfort, Decoration, Safety incl. fire extinguishers, Easy to reach incl. lift), each **Free or Paid** (optional
  price); **bring from outside** answers (food, caterers, vessels, decorators, … Allowed / with a fee / Not allowed);
  **tap-to-pick hall rules**. All three lists are Super Admin settings.
- **Hall page**: every photo (arrows, counter, tiles, full screen), facility sections with Free / Paid badges, outside
  answers, rules, live **availability calendar**.
- **Several days in one booking** (1 to `hall.max_days`, default 3): slot price × days, hall held from the first
  slot start to the last slot end, every day checked.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_15_150000_add_hall_rule_list_and_booking_days`
- `2026_11_15_160000_one_rental_module`

Rollback count for `migrate:rollback --step=`: `2`

Before migrating, note the current rows: `SELECT code, name, is_implemented FROM modules WHERE code IN ('halls','rental');`
and `SELECT m.code, a.scope_type, a.scope_id, a.is_active FROM module_activations a JOIN modules m ON m.id = a.module_id WHERE m.code IN ('halls','rental');`

## Step 10 — scheduled commands that must be listed
- none new

## Test login and walk-through
- `php artisan test-accounts` (password on the command line) refreshes the 7 test halls with facility sections, one
  paid facility (power backup ₹1,500), outside answers and rules.
- Walk-through: home → Rentals door → Function halls → a test hall: see all photos, Free/Paid badges, "Bringing things
  from outside", rules, availability calendar → tap a free day → choose 2 days → bill shows price × 2 → pay (test).
- Hall owner login → edit hall → step 5: tap facilities by section, mark one Paid, answer the outside list, tap rules.

## Browser checks after deploy
- Admin → Modules: one "Rental" row, on where Function halls was on.
- Admin → Rental Settings: Rental kinds at the top; facilities / outside / rules lists filled in.
- Admin → Homepage sections: door name / line fields; the Rentals door.
- `/nellore/rentals` and a hall page as above.

## Admin settings to do after deploy (owner)
- Rental Settings → Rental kinds: leave Vehicles / Equipment off unless offering them.
- Rental Settings → facilities / outside items / rules / days per booking: adjust the lists if needed.
- Homepage sections → Rentals door: name, line and picture if wanted.
