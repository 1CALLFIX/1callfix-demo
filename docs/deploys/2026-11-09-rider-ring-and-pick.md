# Release 2026-11-09 — food/shop rider ring fix + "Pick a rider"

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `3630744` |
| Full test suite | see the commit message |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (new screen elements on the restaurant / shop Orders page) |
| Version | `VERSION` 2026.11.09.4 |

## What's in it
Owner 2026-11-09: "booked a food order but got only 1 ring on rider app, and there is no option for restaurant to
assign it to a local rider". Live order #3 showed it: one 25-second offer to the only nearby rider, then never again,
and the order sat at "ready" with nobody told.
- Every food / shop delivery offer now also sends a phone notification to the rider (kind=offer, the loud offers
  channel), so it rings with the rider app closed. Parcel already did this.
- A rider who only missed an offer is offered it again next round (Super Admin: Shop Settings → Delivery, seconds per
  offer and rounds). A rider who declined is not; one whose offer is still ringing is not offered twice at once.
- When the rounds run out: the restaurant / shop gets a notification, admins with push alerts on and Marketplace Orders
  access in that area get one too, and the order history says so.
- Restaurant / shop → Orders → Active: "Pick a rider" on any delivery order with no rider (cooking or ready). Lists the
  online 1CallFix riders near the restaurant (fresh location, right rider type, not busy, inside the zone radius),
  nearest first, with "Ring". The rider's phone rings and he accepts as usual; nothing is forced, fee and rules unchanged.
  Picking while cooking calls the rider now. A rider who declined can still be rung by hand.
- Admin → Food / Shop Orders → View: the same "Pick a rider" card.
- Order history records who asked which rider.

## Step 5 — migrations that must be pending (exactly these)
- none

Rollback count for `migrate:rollback --step=`: 0

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Restaurant test login → Orders → Active: a delivery order that is cooking or ready shows "Pick a rider"; the list
  shows the online rider with km; "Ring" says "Ringing <name>".
- Rider app (closed) rings / shows a notification for a new food order.
- Admin → Food Orders → View an order waiting for a rider: "Pick a rider" card.

## Admin settings to do after deploy (owner)
- Optional: Shop Settings → Delivery → "Seconds a rider has to accept" (default 25) and "Offer rounds" (default 6).
  With one rider, 6 rounds × 25 s rings him for about 2½ minutes before the restaurant is told.
