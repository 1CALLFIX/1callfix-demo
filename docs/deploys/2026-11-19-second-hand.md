# Release 2026-11-19.3 — Second-hand (people sell used things) + People's posts queue

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `0948eb9` (2026-11-19.2 settings cache) |
| Full test suite | `<fill in>`; known failure only: NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default (.env) |
| Composer | no (`composer.lock` unchanged) |
| Front-end build (steps 6–7) | yes (new classes on the Second-hand screens and the queue) |
| Version | `VERSION` 2026.11.19.3 |

## The name (owner 2026-11-19, checked against the whole site)
- **Second-hand**, never "Sale", "Sell", "Seller" or "Resale": on this site "sale" / "flash sale" / "on sale" are
  shop discounts (22 places), "Seller" is a shop vendor (`/seller` app), and "resale" in India means resale flats.
- Links: `/{city}/second-hand`, items `/{city}/second-hand/second-hand-<name>-in-<area>` (clean, readable, -2 only
  on a clash). `/{city}/used` and `/{city}/used/<item>` 301 to them with the query string kept (UTM survives).
  `second-hand` and `used` are reserved: no city, category or service slug can take them. None existed on live.
- A post opened under the other kind's address (`/rent/<second-hand item>` or the reverse) 301s to its own.

## What's in it
- Same engine as Rent anything (`rental_items.deal` = rent | sell). Off everywhere until switched on per zone.
- **People posting** (Account → "Second-hand: post your used things"): the same 4 tap-first steps; one price with
  "🤝 Price can be discussed"; "🧾 I have the bill" (required for Mobiles and tablets, Laptops and computers: keeps
  stolen phones out); no per-day price, no deposit. Up to 5 up at a time (separate from Rent anything's 3).
  "✅ Mark as sold": leaves every list, frees a slot, the page stays up saying "Sold" (not indexed, schema SoldOut).
- **Customers**: `/{city}/second-hand` with tap filters (kind, area, can deliver, cheapest first); item page with
  photos, ♡ Like, Share, "Price can be discussed", "Bill available", schema UsedCondition; contact panel (owner's
  number for signed-in people, Call, WhatsApp "Is it still available?", Report). Safety line: never pay before
  you have seen it. Breadcrumb Nellore › Second-hand (not under Rentals).
- **Discovery**: a Second-hand door (own drawing) on the city page and homepage with a real count; sitemap lists
  real posts (rent and second-hand; never test accounts).
- **Team**: Admin → Rental → **People's posts** (was "Rent anything"): tabs All posts / For rent / Second-hand,
  type badge on each row, same approve / send back / reports / switch off / feature, same franchise scope. The
  `rental_items.manage` permission label now says "People's posts (rent and second-hand)...".
- **Settings** (Rental Settings, Second-hand sections): switch per zone, kinds, conditions, kinds that need the
  bill, banned list (adds Replica, First copy, Stolen, Motorcycle, Scooter, Scooty: vehicles need papers
  transferred), limit 5, ₹50–₹2,00,000. Photos, days up, reports and the owner's number are shared with Rent anything.
- Contact only: no payment runs through 1CallFix, so no benefit or money-approval rule applies.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_19_100000_add_second_hand_to_rental_items` (adds deal, negotiable, has_bill, sold_at; every existing
  post becomes deal = rent; updates the permission label)

Rollback count for `migrate:rollback --step=`: 1

## Step 10 — scheduled commands that must be listed
- none new

## Test login (owner rule 2026-10-26)
- `php artisan test-accounts create --password='<owner's test password>' --franchise=<id>` adds
  **`test.secondhand@1callfix.test`** (customer, verified mobile 6000000118) with two approved posts: "Test sofa set"
  (₹6,500, price can be discussed, can deliver) and "Test Redmi phone" (₹7,000, bill available). 3 free slots left.
- `test-accounts disable` switches them off and suspends the login.

## Walk-through after deploy (done locally 2026-11-19, all steps passed)
1. Super Admin: Rental Settings → Applies to Zone → franchise → zone → "Second-hand (people sell used things)" **On** → Save.
   (Not tied to Admin → Modules → Rental.)
2. `/<city>` shows the **Second-hand** door; `/<city>/second-hand` lists the two test posts; `/<city>/used` lands there.
3. `test.customer`: Account → "Second-hand: post your used things" → post a phone without ticking the bill → refused
   with "please tick I have the bill" → tick it → Sent, "Waiting for review".
4. `test.franchise`: Rental → **People's posts** → Second-hand tab → View → Approve.
5. `test.customer` on a `test.secondhand` post: Show owner's number → Call / WhatsApp; Report.
6. `test.customer`: Account → Second-hand → Mark as sold → the item page says "Sold" and it leaves the list.

## Admin settings to do after deploy (owner)
- Step 1 above, only for the pilot zone(s).
- Franchise Owner already has `rental_items.manage` if you gave it for Rent anything; otherwise Admin → Roles →
  Franchise Owner → Edit permissions → tick "People's posts (rent and second-hand)".
