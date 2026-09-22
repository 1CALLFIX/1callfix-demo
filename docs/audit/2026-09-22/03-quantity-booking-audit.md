# 03 — Quantity-Based Service Booking Audit

**Ref:** `1CF-COMPREHENSIVE-AUDIT-20260922-1110` · Read-only, no code changed.

## 1. Current architecture (verified)

**Status: Implemented but incomplete** — the backend/data-model behavior the business asked for
already exists; the front-end interaction pattern does not.

### 1.1 Data model

| Concern | Where it lives | Evidence |
|---|---|---|
| Cart-line quantity | `service_cart_items.quantity` (int) | `app/Models/ServiceCartItem.php:20,30`; migration `2026_08_31_001000_create_service_cart_items_table.php` |
| Marketplace product-cart quantity (separate system, out of scope for services) | `cart_items.quantity` | `app/Models/CartItem.php:15` |
| Parent order | `booking_bundles` (`app/Models/BookingBundle.php`) | one row per checkout |
| Individual work unit | `bookings` (`app/Models/Booking.php`) — **has no `quantity` column at all** | grep confirmed no `quantity` field on `Booking` |

`Booking` itself is *always* quantity-1. There is no line-item-with-quantity concept inside the
booking engine — quantity only exists pre-checkout, on the cart line.

### 1.2 What happens at checkout (`app/Livewire/Customer/Checkout.php:244-268`)

```php
foreach ($items as $item) {
    for ($n = 0; $n < max(1, $item->quantity); $n++) {
        $children[] = ['service_id' => $item->service_id, ...];   // no quantity field
    }
}
$action->execute(['children' => $children, ...]);   // CreateBookingBundleAction
```

A cart line with `quantity = 2` is **fanned out into 2 separate `children` entries**, each of
which becomes its own `Booking` row (own dispatch job, own provider assignment, own OTP, own
completion/cancellation lifecycle), all created atomically inside one `DB::transaction` in
`CreateBookingBundleAction::execute()` and wrapped under **one parent `BookingBundle`**
(`app/Actions/CreateBookingBundleAction.php`).

This is, in substance, **exactly the architecture the business asked to be verified/implemented**:
> "one parent booking/order... the system may internally represent individual work units... under
> the same parent booking."

That target already exists on `main` (services-cart line, merged via `c84acfa`) — it does not need
to be built from scratch. What needs to be audited is whether the *presentation* and *interaction*
match the requirement.

### 1.3 Server-side pricing (verified — Phase D authority intact)

Each child booking's price is computed independently, server-side, inside
`CreateBookingAction::createWithinTransaction()`, through the single `effectivePriceFor()` cascade
(flash sale → membership/entitlement → base). No price, franchise, or zone value from the client is
ever trusted (`CreateBookingBundleAction` docblock, confirmed by reading the action). The bundle
total is `round(SUM(child.price_quoted), 2)`, frozen onto `booking_bundles.total_price_quoted` at
creation — not recomputed from a client-sent total.

**Quantity tampering:** since each unit is independently re-priced server-side from
`service_cart_items.quantity` → N loop iterations → N independent `createWithinTransaction()`
calls, a client cannot forge a subtotal; it can only forge the *count* of units requested, which
is bounded by whatever `ServiceCartService::add()`/`changeQty()` validates (not yet audited in this
pass — see open item below).

### 1.4 What is genuinely missing: the requested UI pattern

**Status: Missing.**

The business's required interaction is:
```
Split AC Service
₹2,099
[ − ] [ 2 ] [ + ]
Subtotal: ₹4,198
```
directly beside the price, on the service card / service detail page, with **no forced trip to a
separate cart**.

Verified in `app/Livewire/Customer/Catalog/ServiceShow.php`: the service detail page has **no
`quantity` property at all** — `addToCart()` (line 131) adds the service with whatever the cart
service defaults a new line to (evidence: no `quantity` param passed). The `+/−` stepper exists
only on the **separate cart page**, per-line (`app/Livewire/Customer/Cart/Index.php`,
`changeQty()` — confirmed present from prior session evidence, re-verified quantity lives nowhere
else in `Livewire/Customer/Catalog`).

**Net effect:** the customer currently *must* go to `/cart` to change quantity — the one behavior
the business explicitly said they should not be forced into. The catalog list
(`service-index.blade.php`) and detail page (`service-show.blade.php`) were not inspected for
whether they even show quantity already in the cart; treat that as unverified pending direct
inspection of the two Blade files (not yet read this pass).

## 2. Traceability against the business's audit questions

1. **Current quantity/cart implementation** — DB-backed `ServiceCartItem.quantity`, fanned out at
   checkout into N sibling bookings under one bundle. Implemented and verified.
2. **Does quantity already exist in the DB?** — Yes, on the cart line. Not on `Booking` itself (by
   design — each unit is its own row). Implemented and verified.
3. **Does checkout support multiple units?** — Yes (verified, `Checkout.php:249`). Implemented and
   verified.
4. **Can dispatch handle multiple units?** — Yes: each fanned-out child gets its own
   `ServiceMatchingJob::dispatch($child->id)` call (`CreateBookingBundleAction.php`, post-commit
   loop) — independent provider search per unit, which is operationally correct (two technicians
   may be needed for two AC units, possibly on different days/providers). Implemented and verified.
5. **Server-side pricing** — Confirmed authoritative, one cascade, no client trust. Implemented and
   verified.
6. **Required changes** — see Section 3.
7. **Test matrix required** — see Section 4.

## 3. Required changes (roadmap input — not implemented in this pass)

| # | Change | Layer | Risk |
|---|---|---|---|
| 1 | Add a `quantity` stepper to `ServiceShow` (detail page) and, if product wants it there too, the catalog card — writes directly to the customer's existing `ServiceCartItem` line (create-if-absent, increment-if-present) via the *same* `ServiceCartService` the cart page already uses | Livewire component + Blade | Low — additive UI, reuses tested service |
| 2 | Decide whether adding from the detail page should auto-navigate to cart, stay in place with a toast, or open a slide-over cart preview (business only specified "no forced navigation to a separate cart"; the mechanism is an open decision) | UX | — |
| 3 | Confirm/patch upper-bound and lower-bound guard on quantity (does `ServiceCartService::changeQty()` already reject 0 or negative? Not yet verified this pass — must be audited before UI ships, since 0 must remove the line, not create a phantom booking) | Backend validation | Medium if unguarded |
| 4 | No changes needed to `CreateBookingBundleAction`, `Booking`, or dispatch — the fan-out architecture already satisfies the "one parent, N work units" requirement | — | None |

## 4. Required tests (not yet run this pass — test-plan doc will schedule them)

- Quantity = 1 (baseline, regression-only)
- Quantity = 2 → exactly 2 `Booking` rows created, 1 `BookingBundle`, bundle total = 2× unit price
- Increase quantity in cart after adding from detail page (if stepper added there) — line updates, not duplicated
- Decrease quantity to 1, to 0 (0 must remove the line / not silently keep a 0-qty ghost line)
- Duplicate submission (double-click "place order") — covered by existing idempotency-key path in
  `CreateBookingBundleAction::replay()`; needs a specific quantity>1 regression test to confirm the
  fanned-out children replay identically rather than duplicating
- Refresh mid-checkout — Livewire component state; confirm `schedules[]` keyed by cart-item id
  survives a `mount()` re-run without losing per-line schedule when quantity > 1
- Payment retry after a failed gateway capture on a quantity>1 bundle — confirm all N children stay
  in the same pending/paid state together (not partially settled)

## 5. Open business decision

- **Where does "increase quantity" happen from the detail page — inline only, or with a jump to
  cart/checkout?** Not resolvable from the repo; the business brief describes the visual pattern
  but not the post-click navigation behavior.
