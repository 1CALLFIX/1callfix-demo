# Release 2026-11-09 — pickup code at the counter + rider/partner apps open their own home

Use with `docs/DEPLOY_RUNBOOK.md`. Ships in ONE deploy with `2026-11-09-rider-ring-and-pick.md` and
`2026-11-09-stay-online-reached-store.md` (owner: "run and deploy all at once").

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `3630744` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | **yes** |
| Version | `VERSION` 2026.11.09.6 |

## What's in it
- **Pickup code** (owner: "if same restaurant has 10 orders and 6 riders are there, who will take what? Code would be
  better"; hand-over required). Every delivery order gets a 4-digit code, unique among the restaurant's / shop's open
  orders (orders placed before this get one when first shown). The restaurant's order card shows it big ("write it on
  the bag"); the rider sees it after "Reached restaurant" and says it; the restaurant types it and taps
  "Handed over" (food must be marked ready; a wrong code is refused). Only then can the rider deliver (rider web and the
  worker app API both refuse before). Restaurant card: "Picked up by X", "on the way to the customer". Admin order screen
  shows the code and whether it was handed over. The customer's delivery code is unchanged.
- **Rider / Partner apps open their own home** (owner: "after logging in / creating the account in the rider app it
  showed the customer app; same in the partner app"). Signed out, `/rider` now asks for the rider sign-in (it sent
  people to the customer sign-in). Inside the Rider or Partner app the customer homepage is never shown: rider →
  rider dashboard (or rider sign-in, or the join page for someone who is not a rider yet); partner → the partner start
  page (which sends service partners to /provider and shops / restaurants to /seller). Browsers and the Customer app
  are unchanged.

## Step 5 — migrations that must be pending (exactly these, for the whole combined deploy)
- `2026_11_09_100000_add_rider_at_store_at_to_marketplace_orders`
- `2026_11_09_110000_add_pickup_code_to_marketplace_orders`

Rollback count for `migrate:rollback --step=`: 2

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Restaurant test login → Orders → In progress: a delivery order shows "Pickup code 1234"; with a rider assigned and
  the order ready: "Rider's code" box + "Handed over".
- Rider app: after "Reached restaurant" a big blue pickup code; "Delivered" box appears only after hand-over.
- Rider app signed out → rider sign-in page (not the customer one). Signed in → rider Home, not the customer site.
- Partner app → partner start / dashboard, not the customer site.
- Live order #3 (Anees): he taps "Reached restaurant", says the code, the restaurant enters it, then delivers.
