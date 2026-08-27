# Phase D — Server-Authoritative Pricing & Abuse Hardening

> **Branch:** `feature/customer-web-foundation` · **Starting checkpoint:** `588ba29`
> **Scope:** the price a customer is shown vs. the price they are charged; and the abuse
> boundaries around wallet, commission, ratings and franchise pricing.

---

## 1. The defect

The service price cascade has always had two layers:

```
Service::resolvePrice($franchiseId)     franchise override -> discount_price -> base_price
then the flash-sale layer               an active, scope-covering, not-sold-out sale wins outright
```

Both layers were real, both were tested in isolation, and `FlashSaleService`'s own docblock
described them as one cascade. But **no single place composed them.** Each caller wired them
up by hand, and only one caller did:

| Path | File | Applied |
|---|---|---|
| Customer catalog (web) | `Services/Customer/CatalogPresenter.php:82-85` | both layers |
| Customer catalog (API) | `Http/Resources/Customer/ServiceResource.php:32` | layer 1 only |
| **Booking / checkout** | `Http/Controllers/API/BookingController.php:92` | **layer 1 only** |
| Price sorting | `Services/Catalog/ServiceCatalogQuery.php:278` | layer 1 only (SQL) |

A service with a live 20% flash sale rendered at 400 on the catalog and was booked, charged
and paid at 500.

**Classification: partial implementation, not accidental drift.** The flash-sale engine was
built end-to-end (lifecycle, scope, quantity/per-customer limits, admin screens, badges,
customer display) and then never wired into the booking path. Two independent pieces of
evidence in the repository say so outright:

- `QaCleaner.php:162` — "a redemption is written by `FlashSaleService::redeem()` **during
  booking**". Nothing in `app/` called `redeem()`. The comment described an intention.
- `PHASE_C_DISCOVERY_AND_CATALOG.md` item 4 — "Flash-sale **redemption** must be called at
  booking, not at browse time — nothing in Phase C writes a redemption."

Because no redemption was ever written, `total_quantity_limit` was also unenforceable in
practice: `remaining_quantity` never moved however many bookings took the discount.

## 2. The fix

**No new pricing engine.** The two layers already existed; what did not exist was one
callable composition of them. It was extracted, once, for exactly the reason
`Service::resolvePrice()` itself was extracted from `Livewire\Bookings\Index`:

- **`FlashSaleService::effectivePriceFor()` / `effectivePricesFor()`** — the cascade, end to
  end. Computes nothing of its own; calls `Service::resolvePrice()` then the existing sale
  layer, in the order already documented. The batched form exists for the same N+1 reason
  `priceForMany()` and `BadgeService::badgesForMany()` do, and the single-service form
  delegates to it, so there is one implementation.

Every path now goes through it:

- **`CreateBookingAction::resolveAuthoritativePrice()`** — computes the charge when the
  caller supplies no `price_quoted`, using the scope array the Action *already builds* for
  the module-activation gate (which is exactly the shape `AuthorizationService::scopeCovers()`
  takes — no new concept). A sale that applies is then **redeemed inside the booking
  transaction**, which is where `redeem()`'s own docblock says it belongs; if the sale turns
  out to be sold out or already used by this customer, `redeem()` throws and the booking rolls
  back rather than silently charging a price the customer was never shown.
- **`API\BookingController::store()`** — no longer passes a price at all. It used to pass
  layer one. Removing it leaves exactly one place a customer booking's price can come from.
- **`CatalogPresenter::cards()`** — one call instead of composing by hand.
- **`ServiceCatalogController::services()` / `ServiceResource`** — the headless preview is the
  same cascade, preloaded per page via `Service::setEffectivePrice()` so the grid stays at a
  fixed query count.
- **`Livewire\Bookings\Index::updatedSelectedServiceId()`** — the franchise-override cascade
  was still spelled out by hand here even though `resolvePrice()` was extracted from it;
  finished. Behaviour byte-identical, formatting preserved.

**`price_quoted` is still honoured when explicitly supplied.** That is not a client value: it
is the admin call-centre form's negotiated price, gated on `bookings.create`. No
customer-facing request object accepts a price field — asserted, not assumed
(`PricingAuthorityTest::test_the_customer_booking_request_does_not_accept_a_price_field_at_all`).

## 3. Price data-flow map (after)

```
customer -> POST /api/bookings
              StoreBookingRequest        service_id, address_id, payment_method,
                                         scheduled_at, customer_note  -- NO price field
              BookingController::store   address ownership -> franchise_id/zone_id
                                         customer_id = auth user
                                         (passes NO price)
              CreateBookingAction
                 resolveAuthoritativePrice()
                     Service::resolvePrice(franchise)      SERVER-COMPUTED
                     FlashSaleService sale layer           SERVER-COMPUTED
                 FlashSaleService::redeem()                (records + enforces limits)
                 EntitlementService::resolveAndConsume()   SERVER-COMPUTED (membership)
                 bookings.price_quoted                     <- the only source of truth
              PaymentController::createOrder
                 payments.amount = booking.price_quoted    SERVER-COMPUTED
                 gateway order   = booking.price_quoted    SERVER-COMPUTED
              CompleteBookingAction
                 price_final = price_quoted + approved extras
                 CommissionService splits price_final
```

Client-sourced values that reach pricing: **none.** `ServiceResource.effective_price` and
`CatalogPresenter`'s card price are DISPLAY-ONLY and are never read back.

## 4. Abuse boundaries

| Area | Guard that already existed | Added |
|---|---|---|
| Wallet double-spend | `WalletService::applyTransaction()` — `DB::transaction` + `lockForUpdate` + re-read | `WalletConcurrencyTest` (7) |
| Commission theft | `CompleteBookingAction` — locked row, assigned-provider check, status FSM, completion OTP; `CommissionService` short-circuits on an existing row | `CommissionAbuseTest` (9) |
| Rating manipulation | `ReviewService` — ownership, completed-only, one-per-booking (DB unique), 1..5 | `ReviewAbuseTest` (11) |
| Franchise/commission boundary | `min:0` on the override; `> 0` guard on wallet credits | `FranchisePricingBoundaryTest` (7) |

**Nothing was added to make these pass.** Every guard was already in the code; the tests exist
because "the service checks it" and "the endpoint enforces it" are different claims, and only
the second one matters to an attacker.

Concurrency caveat, same one `HotelAvailabilityConcurrencyTest` already documents: PHPUnit is
single-threaded on an in-memory SQLite connection, so no test here launches two simultaneous
requests. `WalletConcurrencyTest` instead attacks the assumption a double-spend depends on —
that an earlier balance read is still valid — and pins the re-read-under-lock that makes the
row lock work in production.

## 5. Open gaps — reported, not invented

1. **Price sorting still excludes the flash-sale layer.** `orderByEffectivePrice()`'s original
   justification ("checkout doesn't apply sales either") stopped being true in Phase D and has
   been corrected in place. Including it would mean reimplementing `computeFinalPrice()` plus
   the scope and sold-out rules in SQL — a second implementation of the discount rules.
2. **No minimum commission and no minimum booking price exist anywhere.** A zero-priced
   franchise override yields a completed booking paying the platform nothing. Pinned as
   executed behaviour; whether a floor should exist is a pricing-policy decision.
3. **Combined commission rates are unvalidated.** `platform_fee_percent` is capped at 100,
   `commission_value` is uncapped, and nothing checks their sum — 60% + 60% records a
   **negative** provider share. No money moves (the `> 0` credit guard holds), but the
   `commissions` row and every report over it are wrong. Needs a business decision on a
   `platform + franchise <= 100` rule.
4. **Flash sales are not offered on the admin call-centre form.** Unanswered by the repository.
5. **Options (row 17) and coupons (row 34) are schema without a write path.**

## 6. Readiness-matrix re-verification

Five rows re-audited against the code, corrections applied in place and logged in
`CUSTOMER_WEBAPP_READINESS_MATRIX.md` → "Phase D Verification Log". Summary: rows **17, 30,
43 — NO** (named tests do not exercise the claim); rows **18, 27 — PARTLY**; row **34** was
corrected as a consequence. Every named suite passes; the failure was in what they were
claimed to prove, not in whether they were green.
