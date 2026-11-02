# Release 2026-11-02 — Product choices and units (size, colour, weight, portion)

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `3b51f5f` (Shop checkout polish) — stop if the server shows anything else |
| Full test suite | 4371 passed of 4372 (run in 12 chunks, all 425 files, 2026-11-02); known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | **yes** — new styles for the choice buttons; new `app-DxWqQiRS.css` (live has `app-BlckTjyE.css`) |
| Version | `VERSION` file bumped to `2026.11.02.2` |

## What's in it
Owner 2026-11-02: "size, colour, units and all other choices needed to run e-commerce, food delivery".

- **Seller → Products → Units & choices** (one page per product):
  - what one item is: 500 g, 1 kg, 1 L, 6 piece… (customers see "Price for 500 g");
  - up to two choices the customer picks (Size + Colour, Storage + Colour, Weight, Portion…), values typed with
    commas, "Make the list" lays out every combination;
  - each combination: own price (blank = product price), own stock, one of the product's photos, on/off.
  - A dropped combination is switched off, never deleted (old orders keep it). "Remove all choices" sells it as one item again.
- **Super Admin lists** (Admin → Shop Settings → Products): *Units sellers can pick* and *Choice types offered to
  sellers*, one per line. Blank = the built-in list.
- **Customer**: pick each choice on the product page; price, stock and photo follow the pick; sold-out choices greyed
  and cannot be added. Cards show **Choose** (and "From ₹…" when prices differ). The choice name shows in the bag,
  checkout, order page, seller orders, rider screens and invoice.
- **Rule change:** the product's discount now applies to a choice's own price too (before: a choice with its own price
  had no discount). Cash stays off for any discounted item (online-only benefits, unchanged).
- Stock: each choice has its own; the product's stock is their total. Orders take it from the choice; a cancel puts it back.
- Admin → Products → Edit → "Add Variant" now makes a one-type choice ("Option") the customer picks.
- Food: Half/Full portions use this (choice "Portion"). Add-ons, spice level, veg/non-veg are in the Food branch (not here).

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_02_200000_add_choices_and_units_to_products` (5 nullable columns, no data change)

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- none new

## After deploy (owner, on the server)
- `php artisan test-accounts create --password=...` (same as before) adds **Test T-Shirt** (Size M/L/XL × Red/Blue;
  XL ₹350; M / Blue sold out) and **Test Rice** (1 kg / 5 kg ₹330, 10% off) to the Test Shop.

## Test logins and walk-through
- `test.customer@1callfix.test`: `/nellore/shop` → Test T-Shirt card says Choose / From ₹300 → pick L + Red → Add →
  bag shows "(L / Red)"; pick M + Blue → sold out; XL shows ₹350. Test Rice: 5 kg ₹297 with ₹330 crossed out, cash off.
- `test.seller@1callfix.test`: Products → Test T-Shirt → Units & choices → change a stock → Save.
