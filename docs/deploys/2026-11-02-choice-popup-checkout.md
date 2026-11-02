# Release 2026-11-02 — Size/colour pop-up on shop cards + checkout gaps (vs Amazon, Flipkart, Meesho)

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `47a71a7` (Shop Phase A) — stop if the server shows anything else |
| Full test suite | 4386 passed of 4387 (12 chunks, all 427 files, 2026-11-02); known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (new classes for the pop-up) |
| Version | `VERSION` file bumped to `2026.11.02.4` |

## What's in it
- **Size/colour pop-up (Swiggy / Zepto style):** "Choose" on a shop card with sizes, colours or portions opens a panel
  on the same page — photo, price, % off, the choices (red when missing, "2 left"), Add to Cart, then − N in cart +
  and Done. On the shop page, Shop home, the product page's "More from this shop" and the homepage shop rows.
  If the panel is not on a page, Choose opens the product page as before. Same rules as the product page (one shared
  piece of code, `PicksProductChoices`).
- **Checkout, after comparing the three checkouts (owner 2026-11-02):**
  - each address shows the customer's name, the address with city and pincode, and the mobile number;
  - − qty + and Remove on every item (within stock and the limit); the last item removed goes back to the cart;
  - the delivery promise ("Delivered by about …") sits next to the items;
  - under the Pay button: "By placing your order, you agree to the Terms of Use and Privacy Policy" (links).
- New words in Website text & language (English + Telugu): `shop.sheet.close/done/details`,
  `shop_checkout.agree/terms/privacy`.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: `0`

## Step 10 — scheduled commands that must be listed
- none new

## After deploy (owner)
- Admin → Shop Settings → Delivery → **Delivery promise shown to customers** in minutes (e.g. 60). Blank = no arrival
  time shown anywhere (all three big marketplaces show one).

## Walk-through
- `/nellore/shop/test-shop` → Test T-Shirt → **Choose** → the panel opens on the shop page → Add to Cart without a
  size → Size turns red → pick L + Red → Add to Cart → "1 in cart" → Done → the cart bar shows the item.
- Checkout as `test.customer`: name + mobile on the address, − / + on an item changes the total, Remove, the Terms line.
