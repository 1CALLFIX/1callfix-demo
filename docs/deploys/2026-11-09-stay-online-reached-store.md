# Release 2026-11-09 — stay online on a job, restaurant can't close mid-order, "I've reached the restaurant"

Use with `docs/DEPLOY_RUNBOOK.md`. Ships together with `2026-11-09-rider-ring-and-pick.md` (same deploy).

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `3630744` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | **yes** |
| Version | `VERSION` 2026.11.09.5 |

## What's in it (owner 2026-11-09, "very major issues")
- **Rider stays online while carrying a delivery** (parcel, shop, food, a return to bring back). The switch shows locked
  ("You stay online until this delivery is done"); the server refuses going offline too. A rider already offline with a
  delivery sees "Turn on the switch so the customer can follow you".
- **Service partner stays online on the way to a job or while working on it** (`provider_en_route`, `in_progress`).
  Header chip shows "On a job", the Home switch is locked, the server refuses. A job only scheduled (`assigned`) or on
  hold for spares does not block. The automatic "location went silent" offline skips partners on such a job.
- **Restaurant / shop cannot close while an order is in progress** (new, accepted, cooking / packing, ready, until
  delivered): "You have N orders in progress. You can close after they are delivered." Admin can still close a store
  from Stores.
- **Rider sees the order in two stages.** Before "I've reached the restaurant": only the restaurant (name, address,
  directions, call) and "The customer is about X km from the restaurant". After tapping it: "Ask for order <number>",
  every item with quantity, size and add-ons, the customer, address, cash to collect and the code box. Delivering is
  refused until he has tapped it. The restaurant's order card says "Rider X is here for this order". Order history
  records the arrival.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_09_100000_add_rider_at_store_at_to_marketplace_orders`

Rollback count for `migrate:rollback --step=`: 1

## Step 10 — scheduled commands that must be listed
- none new (`providers:expire-stale-online` changed behaviour only)

## Browser checks after deploy
- Rider with an order in hand: switch locked, tapping does nothing; card shows restaurant + km only; "I've reached the
  restaurant" shows items + customer.
- Restaurant with an order in progress: Open/closed switch says it can't close yet.
- Partner on a job (on the way / working): header says "On a job".
- Order #3 (Anees, live): he must tap "I've reached the restaurant" before "Delivered" (the column is new and empty).

## Admin settings to do after deploy (owner)
- none
