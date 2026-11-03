# Release 2026-11-03 — Seller change rules + add many products (master list, photo, Excel) + seller page fixes

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `b812e85` (phone pay bar) — stop if the server shows anything else |
| Full test suite | 4405 passed of 4406 (12 chunks, all 429 files, 2026-11-03); known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (new classes: photo box, change screens, Add many) |
| Version | `VERSION` file bumped to `2026.11.03.1` |

## What's in it
**Owner rule set 2026-11-03**, for every item (shop product, food, any item) and every shop:

| Change | Rule |
|---|---|
| Price, discount, stock, on/off, limit per order, choices' price/stock | live at once |
| New item | waits for approval, hidden until then (as before) |
| Item name, photos, description, category | **item stays live with the old details**; the new ones wait for approval |
| Shop logo, description, delivery fee, minimum order | live at once |
| Shop name | admin only (as before) |
| Shop mobile number | changes only after an OTP to the new number (Firebase, as at sign-up) |
| Shop address / map pin, GSTIN, PAN | admin approval; riders and invoices keep the old ones until then |

- **Admin → Changes to approve** (Marketplace menu, `products.manage` for items, `stores.manage` for shops, scoped to
  the admin's franchise / zone / city): old and new side by side (photos, map link), **Approve** or **Reject** with a
  reason the seller sees. Decided once; audit-logged ("Change sent for approval", "Approved change", "Rejected change").
- Seller sees "New name, photos waiting for approval · customers see the old ones" or "Change not approved: <reason>".
  Saving again replaces the waiting change; going back to the live details withdraws it.
- Seller fixes from the owner's walk-through: **Edit / Add a product moves the page up to the form**, Save moves up to
  "Saved." (Products, Units & choices, Shop); an **"Add photo" box** with a picture icon instead of "Choose Files" —
  each pick adds (up to 5), a new photo can be taken out, the first is the main one.

## Adding many products at once (owner 2026-11-03: "if adding products is hard, shops will not join")
- **Seller → Products → Add many (list or photo):**
  - *From our list:* the 1CallFix master list (804 items, 15 categories) by category, type and search; tick items,
    type price and stock, add them all. Items already in the shop are marked and skipped.
  - *From a photo:* photograph a price list, bill, shelf or menu board (up to 3 photos). Claude (`claude-opus-5-5`)
    reads it **in the background** (queue job `ReadProductPhotoJob`, runs once, never retried, the photo is deleted
    after reading). The items found join the same list; nothing is added until the seller checks the price and stock.
    Shop Settings → Sellers: *Sellers can add products from a photo* (on) and *Photos each shop can read per day* (20).
    **Off until `ANTHROPIC_API_KEY` is in the server `.env`.** Cost: a few rupees per photo.
- **Seller → Products → Change many in Excel:** download my products (CSV, opens in Excel), change price, discount,
  stock or show yes/no, upload. A new name on a live item waits for approval; an empty id adds a product; bad rows are
  listed by row number and skipped.
- **Admin → Products import:** the shop and category by name ("Clothing > T-Shirts"), up to 5 photos (`|`), and the
  unit (`unit_value`, `unit`). Imported products now get clean links (`steel-bottle`, `-2` on a repeat) — before, every
  import added a random code, against the link rule. The same fix for new shop categories, hotels and properties.
- New command `php artisan catalog:load-master [--dry-run]` loads `database/data/master_catalog.csv`. Safe to run again;
  never changes an existing category; creates missing ones ON for Launch, OFF for Later.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_03_100000_create_pending_changes_table`
- `2026_11_03_110000_create_catalog_items_table`
- `2026_11_03_120000_create_product_photo_scans_table`

Rollback count for `migrate:rollback --step=`: `3`

## Step 10 — scheduled commands that must be listed
- none new

## After deploy (owner)
- Load the master list: `php artisan catalog:load-master --dry-run` (read the counts), then `php artisan catalog:load-master`.
- Photo reading: add `ANTHROPIC_API_KEY=...` to `/home/1callfix.com/public_html/.env` (never in git), then
  `php artisan config:cache`. Without it the photo tab says "not switched on yet".
- Step 9 worker restart is needed (new queue job).
- Admin → Products: approve **Test 18% GST Item** and **Test Discount Item** once (an earlier photo edit sent them back
  under the old rule; they stay approved from now on).
- Firebase: the seller Shop page now sends OTPs; the domain is already authorised for sign-up.

## Walk-through
- `test.seller`: Products → Edit (an item far down) → the page moves to the form → change price + name + add a photo
  → Save → price live at once; list says "New name, photos waiting for approval"; the shop page still shows the old name.
- Admin → Changes to approve → old vs new → Approve → the shop shows the new name and photo. Another change → Reject
  with a reason → seller sees "Change not approved: <reason>".
- Seller → Shop: change the address → "will be used once 1CallFix approves it"; Change number → OTP → number changed.
- Seller → Products → Add many → From our list → Clothing → tick 2 items → price + stock → Add → they wait for approval.
- From a photo (after the key is set): photograph a printed price list → "Reading your photo…" → items appear → fill
  the missing prices → Add.
- Change many in Excel → Download → change one price → upload → "1 changed".
