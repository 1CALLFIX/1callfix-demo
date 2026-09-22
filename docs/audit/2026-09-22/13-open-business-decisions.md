# 13 — Open Business Decisions

**Ref:** `1CF-COMPREHENSIVE-AUDIT-20260922-1110`

Per the brief's rule: only decisions that genuinely cannot be resolved from the repository or from
the business rules already given in the brief. Each item below could not be answered by reading
code, and each is a real blocker for at least one roadmap phase in doc 12.

## 1. Cash remittance mechanism (blocks doc 04's roadmap items #1, #2, #6)

- **What does `payment_status = paid` mean for a cash booking** — that the customer physically paid
  the provider, or that the platform has been remitted and verified? Today it tracks neither.
- **Accepted remittance methods** — cash deposit, UPI, bank transfer, or a mix by country/franchise.
- **Remittance cadence** — per-booking, daily, weekly, or provider-discretion-with-a-cap.
- **Debt threshold for dispatch restriction** — whether/when unresolved cash debt should block a
  provider from new job offers, and at what amount or age.

## 2. Dispatch timing specifics (blocks doc 05's roadmap item #6)

- Should the 5-minute / 30-minute windows be global constants, or configurable per zone, franchise,
  or service type? The brief's diagram gives fixed numbers; the existing `Setting` system (used for
  `dispatch.offer_timeout_seconds`, `dispatch.max_rounds`) supports either.
- What happens to the rest of a `BookingBundle` when one child booking auto-cancels at T+30 — does
  the bundle proceed with the remaining children, or does the customer need a partial-cancellation
  decision point?

## 3. Quantity-stepper navigation behavior (blocks doc 03's roadmap item #2)

When a customer increases quantity from the service detail page (once built), does the app: stay in
place with a toast/inline subtotal update, auto-navigate to cart, or open a slide-over cart preview?
The business specified the visual pattern and the "no forced navigation" constraint, not the
resulting interaction.

## 4. Marketplace/product module launch scope (blocks doc 02's product-section item)

`ModuleActivationService` already exists to gate the marketplace/product module per franchise/zone
— the mechanism is built. Whether it should be **on** for the primary launch is a business call the
repo cannot answer.

## 5. Provider payout on membership-waived jobs (blocks doc 08's entire roadmap)

When a membership benefit waives a charge (e.g. visiting fee) for the customer, does the provider
still receive their normal share for that component, or is it absorbed by the platform/franchise?
This single decision gates the merge of the entire membership-completion branch
(`feature/membership-prime-silver`).

## 6. Financial-correction/reversal process (partially blocks doc 04's roadmap and doc 06 §3)

The brief requires an auditable void/reverse/correct process for financial records rather than
deletion. A reversal path for `ProviderCommissionReceivable` specifically was not found in this
pass (flagged **unverified**, not confirmed-missing, in doc 04 §2.7) — if it truly doesn't exist,
the business needs to define the authorization level required to reverse a cash-commission charge
(e.g. a data-entry correction) before that capability is built.

## 7. History-rewrite decision for the leaked DB credential (doc 09 §1)

Rotating the credential is not optional and doesn't need business sign-off. Whether to also rewrite
git history to remove the string from old commits is a separate, disruptive operation (breaks every
existing clone/fork, rewrites commit hashes) that needs an explicit decision weighed against who has
had access to the repository and the operational cost of a history rewrite.
