# Release 2026-11-03 — Seller change rules (like Amazon / Flipkart / Swiggy) + seller page fixes

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `b812e85` (phone pay bar) — stop if the server shows anything else |
| Full test suite | 4396 passed of 4397 (12 chunks, all 428 files, 2026-11-03); known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (new classes: photo box, change screens) |
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

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_03_100000_create_pending_changes_table`

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- none new

## After deploy (owner)
- Admin → Products: approve **Test 18% GST Item** and **Test Discount Item** once (an earlier photo edit sent them back
  under the old rule; they stay approved from now on).
- Firebase: the seller Shop page now sends OTPs; the domain is already authorised for sign-up.

## Walk-through
- `test.seller`: Products → Edit (an item far down) → the page moves to the form → change price + name + add a photo
  → Save → price live at once; list says "New name, photos waiting for approval"; the shop page still shows the old name.
- Admin → Changes to approve → old vs new → Approve → the shop shows the new name and photo. Another change → Reject
  with a reason → seller sees "Change not approved: <reason>".
- Seller → Shop: change the address → "will be used once 1CallFix approves it"; Change number → OTP → number changed.
