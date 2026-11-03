# Release 2026-11-03 — Food F3: customer food pages (Food stays OFF until the owner switches it on)

Use with `docs/DEPLOY_RUNBOOK.md`. Deploy together with F1 and F2 (`2026-11-03-food-f1-restaurants.md`,
`2026-11-03-food-f2-order-flow.md`): one walk-through. The branch now sits on top of shop Phase A (`47a71a7`), so
Phase A ships in the same deploy if it has not gone out first.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Branch | `feature/food-f1-restaurants` (F1 + F2 + F3, rebased on `feature/shop-phase-a` 47a71a7) |
| Live before | `<hash currently on the server>` |
| Full test suite | F3: Food 34, Marketplace 191, Seo 137, CustomerWeb 528, Housekeeping 14 all pass. Run the full suite again at merge |
| Composer | no |
| Front-end build (steps 6–7) | yes (new pages and classes) |
| Version | bump `VERSION` at merge time |

## What's in it
- `/{city}/food`: the city's restaurants, open ones first. Each card shows its cuisines, "Ready in about N min"
  (its cooking time + busy minutes), the delivery fee, cost for two and Pure veg. Filters: Pure veg, cuisine (Food
  Settings list), search by restaurant, cuisine or dish. Closed: "Opens at 7:00 PM". Never listed: no FSSAI number
  (while Food Settings needs it), no dish on the menu, test restaurants once "Hide test shops" is on.
- `/{city}/food/{restaurant}`: the menu by the restaurant's own sections, veg / egg / non-veg mark on every dish,
  Veg only, search, FSSAI licence number at the bottom (the law asks for it). Sold out today = not shown. Closed =
  menu visible, nothing can be added.
- A dish with a portion (Half / Full) or add-on groups opens a sheet: each group says its rule ("Pick 1", "Optional,
  up to 2") and the price updates as you choose. Each different choice is its own cart line.
- One restaurant per food cart: adding from a second restaurant asks "Start a new food cart?". Shop items in the
  same cart are never touched.
- A visitor can fill the food cart without signing in (same as the shop); the dishes and their add-ons join their own
  cart at sign-in.
- Cart and checkout show add-ons and price them in; checkout adds Packaging and "GST on food" lines and says before
  paying when the address is too far ("delivers up to 3 km. Your address is 5 km away."). The total shown is the
  order total (tested).
- Order page for food: "Waiting for the restaurant to accept (by 7:40 PM)…", "Being cooked. Ready about 7:55 PM.",
  add-ons on each dish, Cooking step; Cancel only before the restaurant accepts; no Return button (problems with a
  food order come in F4).
- Homepage door "Food" and a city hub entry (live line: restaurants open now) where Food is on; Admin → Homepage
  sections has the new door (on/off, order, picture). Sitemap lists /{city}/food and each real restaurant.
- New Food Settings key: **Orders → Most of one dish in a cart** (default 20). A dish's own "Limit per order" is
  lower when the restaurant sets it.
- Every customer word is in Admin → Site text → Food (English + Telugu), including the checkout refusals.

## Step 5 — migrations
None new in F3. Pending for F1–F3 together: `2026_11_03_100000_add_restaurant_fields_for_food`,
`2026_11_03_110000_add_food_order_flow_fields` (plus Phase A's `2026_11_02_200000_add_choices_and_units_to_products`
if Phase A is not live yet). Check against `migrate:status` before migrating.

## Step 10 — scheduled commands
None new in F3 (F2's `food:expire-unaccepted` and `food:call-riders` must be listed).

## Walk-through (test logins; Food ON only for the test franchise)
1. Admin → Modules: Food on for the test franchise. Open `/{city}/food`: Test Restaurant shows with "Ready in about
   … min". Pure veg hides it (it has a biryani); search "dosa" finds it.
2. Its menu: Test Sold Out Dish and Test Hidden Dish are not shown. Add Test Veg Meals (straight in). Add Test Masala
   Dosa: the sheet says "Pick 1"; "Add item" without a chutney is refused; pick one, add.
3. Cart: the dosa line shows "+ <chutney>"; checkout shows Packaging / GST only if those Food Settings are set.
4. Place a cash pickup order. Order page: "Waiting for the restaurant to accept (by …)". As `test.restaurant`:
   accept with a time; the customer page now says "Ready about …".
5. Switch Food off again for that franchise if it is not going live yet: `/{city}/food` returns 404.

## Admin settings after deploy (owner)
- Food Settings → Orders → Most of one dish in a cart (20 is fine to start).
- Admin → Homepage sections → Food door: upload a picture if you want one (a drawing is built in).
- Admin → Site text → Food: read the Telugu once.
