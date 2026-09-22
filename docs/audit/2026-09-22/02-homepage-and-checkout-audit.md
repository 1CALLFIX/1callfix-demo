# 02 — Homepage and Checkout UX Audit

**Ref:** `1CF-COMPREHENSIVE-AUDIT-20260922-1110` · Read-only, no code changed.

**Important caveat, stated up front per the audit's own honesty rule:** the brief references
"supplied screenshots" as UX reference material. **No screenshots or images were attached to or
receivable by this session** — only text. This document therefore audits what exists in the
repository against the brief's *written* description of the desired journey (§8–9 of the brief),
not against the visual reference. Any comparison to the actual screenshots must happen in a
follow-up pass once they can be supplied to a session that can view images.

## 1. What already exists (verified from prior session history + this pass)

Per project memory and confirmed still on `main` this pass (`git log` checks in this session): the
customer web homepage, search, banners, and cart/checkout were built and deployed across several
prior phases — homepage search/banner redesign, blue design system, live order tracking via
`wire:poll`. This is not being re-audited from scratch; this pass verifies specific open questions
the brief raises.

## 2. Section-by-section findings

### A. Automatic location
- `app/Services/Customer/CustomerLocationContext.php` resolves zone/location server-side.
- Browser geolocation is used in a dedicated `location-picker.blade.php` component (confirmed via
  grep for `navigator.geolocation`), not inline on the homepage itself — this is a reasonable,
  common pattern (topbar location picker) but was not verified this pass for fallback behavior on
  permission-denial or GPS failure. **Unverified.**
- `GOOGLE_MAPS_API_KEY` is read server-side only from `config/services.php` (`env()`-sourced) —
  no hardcoded key found in any Blade/JS file. Where it *is* surfaced to the client (any Maps
  JS-API `<script src>` tag), that's expected for that API (Maps JS keys are inherently
  client-visible) but should be **domain-restricted in the Google Cloud Console** — that
  restriction is a Google-side config, not verifiable from the repo. **Flagged for production
  verification, not a code gap.**

### B. Search
- Confirmed present and merged to `main` per prior session work (autocomplete across categories +
  services, grouped, keyboard nav) — not re-verified line-by-line this pass since it was built and
  tested in a documented prior phase.

### C. Top promotional banner
- `Banners/Manage.php` admin screen exists with a filter property (doc 06), soft-deletes, and
  (per prior session work) per-call-site rotation speed config (`config/banners.php`). Not
  re-verified line-by-line this pass.

### D. Service categories
- **No "estimated arrival time" copy or logic found anywhere on the homepage** (`Home.php`,
  `home.blade.php` — searched for `eta|arrival|available in`-style text, zero matches). This means
  the business's "estimated arrival time, if supported and accurate" concern is moot in its risky
  form (there is no *fake/hardcoded* ETA to worry about) but the feature itself is simply **not
  implemented** — category tiles show no availability/ETA signal at all today.

### E. Product section (marketplace)
- `Products/Manage.php` and `Stores/Manage.php` exist as admin screens (doc 06), and
  `app/Models/CartItem.php` is the separate marketplace-product cart (explicitly distinct from the
  services cart per prior session work — see project memory on services-cart). Whether the
  marketplace/product module is actually **in scope for the primary launch** (per the brief's own
  instruction: "Do not activate out-of-scope modules without confirming the existing launch scope")
  is an **open business decision**, not something the repo can answer — `app/Services/ModuleActivationService.php`
  exists specifically to gate modules on/off per franchise/zone, implying this is already a
  deliberate, admin-controlled decision point rather than an all-or-nothing code question.

## 3. Checkout flow — cross-reference to doc 03

The full checkout journey (service detail → cart → address → schedule → price review → payment →
confirmation → dispatch tracking) is traced in detail in **doc 03 (quantity booking audit)**, since
the two are the same code path. Headline result repeated here for completeness: the journey is
real and server-priced end to end, **except** the inline `+/−` quantity control the business wants
on the service card itself — that only exists on the separate cart page today (doc 03 §1.4).

## 4. Price integrity (cross-reference to docs 03/04)

Already confirmed in doc 03: quantity, unit price, and total are all server-recomputed via the
single Phase-D `effectivePriceFor()` cascade; the client-sent `children[]` array carries no price.
Coupon/membership interaction with this cascade was **not independently re-verified in this pass**
beyond what doc 08 (membership) covers — flagged as a cross-cutting item for the roadmap's test
plan (doc 11).

## 5. Concurrency / integrity checks required (not run this pass)

The brief asks specifically about: quantity tampering, price tampering, coupon abuse, duplicate
checkout, replayed payment requests, refresh during checkout, back-button behavior, payment
timeout, partial failure, double booking, concurrent submission. Of these, **duplicate
checkout/replayed submission is already handled** — `CreateBookingBundleAction`'s idempotency-key
+ request-fingerprint mechanism (doc 03 §1.3, confirmed by reading the action in full) directly
covers double-click/duplicate-submission and replay. The remainder (concurrent submission across
two browser tabs, payment timeout/partial failure recovery, back-button mid-flow) were **not
independently tested this pass** — they require either a live/staging run or dedicated Feature
tests, scheduled in doc 11.

## 6. Summary status

| Item | Status |
|---|---|
| Location resolution (server-side) | Implemented, sampled |
| Location fallback on permission denial | Unverified |
| Search | Implemented (prior phase, not re-verified this pass) |
| Banners | Implemented (prior phase, not re-verified this pass) |
| Category availability/ETA | Missing (no fake data risk — simply absent) |
| Product/marketplace scope | Implemented (module-gated); launch inclusion is an open business decision |
| Inline quantity stepper on service card | **Missing** — see doc 03 |
| Server-authoritative pricing at checkout | Implemented and verified |
| Duplicate-submission protection | Implemented and verified |
| Concurrent-submission / payment-timeout recovery | Unverified — needs dedicated testing |

## 7. Open business decision

- **Is the marketplace/product module in scope for this launch phase**, given
  `ModuleActivationService` already exists to gate it per franchise/zone? Not resolvable from the
  repo alone.
