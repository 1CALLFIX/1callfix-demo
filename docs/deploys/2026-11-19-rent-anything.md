# Release 2026-11-19 — Rent anything (OLX-style, posted by people, checked by the franchise team)

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1), after merging `feature/rent-anything` |
| Live before | `42e9d3ee` (2026-11-18.2 rental zone scope); confirm with `git log -1` on the server |
| Full test suite | 4988/4989 passed (2026-11-19, `feature/rent-anything` with Stage 4); re-run on `main` at step 1; known failure only: NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default (.env) |
| Composer | no (`composer.lock` unchanged) |
| Front-end build (steps 6–7) | yes (new customer and admin screens) |
| Version | `VERSION` 2026.11.19 |

## What's in it
- Owner decision 2026-11-18: anyone with a verified mobile number can post things they rent out (camera, speaker,
  furniture, tools). Phone-verified, franchise-moderated, Nellore pilot. **Off everywhere until switched on per zone.**
- Customers: `/{city}/rent` "Rent anything near you" with tap filters (kind, area, 🚚 can deliver, cheapest first),
  the item page (photos, ♡ Like, Share, price / deposit / kind / condition boxes), a contact panel ("Show owner's
  number" for signed-in people only, Call, WhatsApp with the message filled in), "🚩 Report this item" with tap
  reasons (3 reports hide the item until the team checks it). Rentals page gets a violet "Rent anything" tile.
- People posting: Account → Rent out your things, 4 numbered tap-first steps, hide / show / renew, at most 3 up at a
  time, banned-word list.
- Team: Admin → Rental → Rent anything (Waiting for review, Reported, Approved, Needs changes, All; oldest first).
  Approve, Send back (reason required), Item is fine / Agree take it down, Switch off, Feature. Each franchise sees only
  its own zone's items.
- Found in the 2026-11-19 local walk-through and fixed in this release:
  - The photo tip on Property for rent, Vehicles for rent and Rent anything said "the hall from the entrance, the
    stage…". Each now has its own tip (`home_photo`, `vehicle_photo`, `item_photo`; same size rules as halls).
    Property and Vehicles are already live, so owners there see the corrected tip after this deploy.
  - The "Rental Settings →" link on the Rent anything, Property and Vehicles queues is shown only to Super Admin (the
    page is Super Admin only; franchise teams got a 403).
  - The empty Rent page now has a "➕ Rent out your things" button next to "Post it free".
  - Rental Settings intro lists all five kinds.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_18_120000_create_rental_items_table`
- `2026_11_18_120100_rental_items_permission`

Rollback count for `migrate:rollback --step=`: 2

## Step 10 — scheduled commands that must be listed
- none new (`homes:tidy` unchanged)

## Test login (owner rule 2026-10-26)
- `php artisan test-accounts create --password='<owner's test password>' --franchise=<id>` adds **`test.renter@1callfix.test`**
  (customer, verified mobile 6000000117) with two approved items: "Test Sony camera" (₹800 / day, ₹5,000 deposit) and
  "Test party lights set" (₹1,500 / event, can deliver). One free slot left to try posting.
- `test-accounts disable` switches both items off and suspends the login.

## Walk-through after deploy (with the test logins)
1. Super Admin: Admin → Modules → Zone → the pilot zone → Rental **on** (if not already).
2. Super Admin: Rental Settings → Applies to **Zone** → franchise → zone → "Rent anything (items people post)" **On** → Save.
3. Super Admin: Admin → Roles → **Franchise Owner** (and any other franchise role that should moderate) → Edit
   permissions → tick "Rent anything: approve posted items…" → Save permissions. Without this the franchise team gets
   "403 You do not have permission" (only Super Admin holds it after the migration, unless a role already had
   Property for rent approval).
4. Run `test-accounts create` (above).
5. Guest: `/<city>/rent` shows the two test items; an item page shows "Sign in to see the owner's number", never the number.
6. `test.customer`: Account → Rent out your things → post one item (2 photos, kind, price, area) → "Waiting for review".
7. `test.franchise` at /admin/login: Rental → Rent anything → Waiting for review (1) → View → Approve.
8. `test.customer` on a `test.renter` item: Show owner's number → Call / WhatsApp; Report this item → "Thank you".
9. `test.franchise`: Reported tab shows the report → "Item is fine".

## Admin settings to do after deploy (owner)
- Steps 1–3 above, only for the pilot zone(s). Everything else keeps Rent anything off.
- Optional: Rental Settings → Rent anything · Choices / Posting / Safety / Contact (defaults: 3 items per person,
  30 days up, ₹20–₹50,000, 3 reports hide, 10 numbers per day per customer).
