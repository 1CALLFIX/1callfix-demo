# Release 2026-11-12 — Hotels step 2: offers, children, voucher and invoice

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `9f2d90d` |
| Full test suite | see the deploy report; known failures only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (new layout classes on the hotel search) |
| Version | `VERSION` file bumped to `2026.11.12.2` |

## What's in it
Owner 2026-11-12: "make sure we can stand in the current aggregators" — step 2 of the comparison.
- **Coupons on hotel stays.** Admin → Coupons → For: "Hotel stays". The coupon comes off the room price; GST is on what
  is left; the hotel's room price (commission base) is unchanged — 1CallFix funds the coupon. Online and wallet only
  (thumb rule; cash / pay at hotel refused, with tests). Held at booking, given back when the booking ends unused
  (unpaid, declined, not accepted, cancelled), counted as used when the stay completes or the guest does not arrive.
  A late cancellation charges the nights at the price the guest actually paid. Website + app (`coupon_code`).
- **Children.** Search, hotel page and booking ask for children; each room's own "Children per room" (hotel portal)
  limits them; rooms that cannot take them are not offered. App: `number_of_children`.
- **Booking voucher and tax invoice** (print / save as PDF) from the customer's booking page: voucher from confirmation
  (check-in code, hotel, times, guests, price), tax invoice after check-out (hotel GSTIN, the guest's company GSTIN when
  given, taxable value after coupon, GST). Only the guest, the hotel's own account or an admin with
  hotel_reservations.view can open them.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_13_100000_add_coupons_to_hotel_reservations` (hotel_reservations.coupon_code / coupon_discount_amount,
  coupon_usages.hotel_reservation_id)

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- none new (`hotels:expire-unpaid`, `hotels:expire-unaccepted`, `hotels:complete-stays` still listed)

## Browser checks after deploy
- /nellore/hotels: Children box in the search; page does not scroll sideways on a phone.
- Booking page (`/hotels/book?...`): "Have a coupon?" box shows while coupons are on.
- Admin → Coupons → New: "For" has "Hotel stays".

## Admin settings to do after deploy (owner)
- To run a hotel offer: Admin → Coupons → New → For "Hotel stays", scope (Global or cities), value, limits.
- Hotels: set "Children per room" on each room (default 0 = no children).
- Still pending: GST bands in Hotel Settings → Tax (until then hotels show "Booking opens soon").
- Ask the CA: GST on a discounted stay is worked on the price after the coupon (each night keeps its band).
