# Release 2026-11-02 — Shop Phase A (cart and checkout like the big marketplaces)

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `d774f69` (Product choices) — stop if the server shows anything else |
| Full test suite | 4379 passed of 4380 (run in 12 chunks, all 426 files, 2026-11-02); known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | **yes** — new `app-JX2w-MaR.css` (live has `app-DxWqQiRS.css`) |
| Version | `VERSION` file bumped to `2026.11.02.3` |

## What's in it
From the owner's walk-through of Amazon, Flipkart and Meesho (2026-11-02), item selection to payment.

- **Visitors can fill a cart without signing in** (kept in their browser session, no stock held). `/cart` opens for
  them; "Sign in to checkout" asks for sign-in and comes back to that shop's checkout. The cart joins their own cart
  the moment they sign in (any way: password, OTP, Google), within stock. Services still need sign-in.
- **Product page:** **Add to Cart** (was "Add to bag") + **Buy now** (straight to checkout); price with % off and the
  price before; "Inclusive of all taxes"; a missing size is marked in red on the size itself; "2 left" under scarce
  sizes; delivery promise.
- **Owner decision — cash at full price ("Meesho way"):** cash is always offered. A cash order pays the **full price**
  of every item and the delivery fee; online and wallet get the offer price and free delivery. Checkout shows each
  way to pay with its own amount and "Save ₹X". No discount ever reaches a cash order (proven by tests). Before: cash
  was switched off whenever a discounted item was in the cart.
- **Cart:** large photos, size/colour under the name, offer prices with the price before, free-delivery bar, green
  "You save ₹X with online payment", total and one checkout button per shop.
- **Checkout:** one page in three numbered blocks (1 Delivery · 2 Your items · 3 Payment), a price box like Flipkart's
  (Price · Offer discount · Coupon · Delivery · Total · "You save ₹X on this order"), coupon folded under payment.
- **Delivery promise** (new Shop Settings → Delivery): *Delivery promise shown to customers* and *Pickup promise*
  in minutes. Blank (default) = nothing shown. Shows "Delivered by about 6:30 PM today" while the shop is open.
- **Test shops show to everyone while the shop is being built** (owner 2026-11-02: "allow all, restrictions once
  everything is tested"). New Shop Settings → Sellers switch **Hide test shops from customers**, default OFF.
  Turn it ON at go-live. Search engines never get test shops either way (noindex, not in the sitemap).
- Test Shop wording fix (no internal name in the T-Shirt text; rice has no unit).
- Every new word in Website text & language (English + Telugu). Note: "bag" → "cart" changed the default English;
  if someone had already edited those two texts in Website text, their edit stays.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: `0`

## Step 10 — scheduled commands that must be listed
- none new

## After deploy (owner)
- At go-live: Admin → Shop Settings → Sellers → **Hide test shops from customers** ON, and `test-accounts disable`.
- Optional: Admin → Shop Settings → Delivery → set the delivery promise minutes (e.g. 60) and pickup minutes (e.g. 30).
- Optional: re-run `php artisan test-accounts create --password=...` for the Test Shop wording fix.

## Walk-through
- Signed out: `/nellore/shop` shows the Test Shop (switch OFF) → add items as a visitor → cart → "Sign in to checkout"
  → sign in as `test.customer` → lands on checkout with the items.
- `test.customer@1callfix.test`: Test T-Shirt → Buy now without a size → Size box turns red → pick L + Red → Buy now →
  checkout. Payment shows Online ₹… "Save ₹…" and Cash at the full price. Test Rice 5 kg: online ₹297, cash ₹330.
- Cart: photos, sizes, "You save", free-delivery bar.
