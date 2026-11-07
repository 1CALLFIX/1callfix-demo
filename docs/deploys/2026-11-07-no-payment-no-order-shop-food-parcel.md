# Release 2026-11-07 — No payment, no order: shop, food, parcel and the services cart

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `87aa3e3` |
| Full test suite | 4612 tests, 4611 passed; 7 tests written for the old behaviour updated (cart emptied before payment, two unpaid orders per customer, seller "unpaid" error, message counts); known NotificationCenterAuditTest .env failure only |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (new Blade classes on the payment step) |
| Version | `VERSION` 2026.11.07.12 |

## Owner rule 2026-11-07 ("Meesho way")
"If the user selects to pay online then only it is valid … only if payment is successfully done the order confirms."
Services were done in 87aa3e3; this release does the same for shop, food, parcel and the services cart.

## Found and fixed
- **Shops and restaurants could see unpaid online orders** in New orders ("Waiting for payment") and in their counts.
  Now they never see them (list, counts, dashboard, and opening one by id → 404).
- The unpaid timeout (shop/food `marketplace.unpaid_expiry_minutes`, parcel `parcel.unpaid_expiry_minutes`, default
  30, unchanged) sent the customer an **"order cancelled"** message for an order that never existed. Now quiet.
- The cart was emptied as soon as an unpaid online order was created, so leaving the payment window lost it.
  **Now the cart stays until the payment succeeds** (emptied on capture).
- Ordering again after leaving the payment window left two orders. **Now the new order replaces the unpaid one**
  (its stock and coupon go back first — the coupon then counts once).
- The customer's unpaid order page looked like a placed order (progress bar "Order placed"). Now it is a payment
  step: "Complete your payment — your order is placed only after the payment", Pay, **Change payment method (cart
  kept)**, Don't order now. After paying: "Confirming your payment…" (no second Pay button). Ended: "Payment not
  completed — no order was made; refunded automatically if money left; cart kept". Words: Website text → "Payment
  step" (en + te).
- **Services cart (bundles):** no "booking created" messages until paid (sent on capture); unpaid bundles end after
  the Site controls minutes like single bookings; a late payment on an ended bundle is refunded in full (it used to
  be able to mark cancelled bookings paid); a new booking replaces an unpaid bundle (after the repeated-tap check).
- Admin → Shop/Food orders and Parcel orders: **"Payment attempts (not orders)"** button. Admin Dashboard, Food and
  Parcel dashboards and the customer API lists count only real orders.
- Unchanged and already right: no "order placed" message / seller alert / rider search before capture; no fee on
  an unpaid order; late money refunded in full; an ended order can't be paid.

## Step 5 — migrations that must be pending
- none

## Step 10 — scheduled commands that must be listed
- none new (`marketplace:expire-unpaid`, `parcel:expire-unpaid`, `bookings:expire-unpaid-attempts` already listed)

## Browser checks after deploy
- Shop: add an item, pay Online, close Razorpay → "Complete your payment"; the cart still has the item; the shop's
  New orders does not show it; Change payment method → Cash → place → ONE order.
- Parcel: book Online, close Razorpay → "Complete your payment"; My parcels does not list it.
