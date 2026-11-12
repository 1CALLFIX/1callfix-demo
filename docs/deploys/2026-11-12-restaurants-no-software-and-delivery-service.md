# Release 2026-11-12 — Restaurants: no-software tools + delivery service for their own orders

Use with `docs/DEPLOY_RUNBOOK.md`. Goes out together with `2026-11-12-partners-part-b.md` if that is not live yet.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `0a2400c` (part B not deployed yet) |
| Full test suite | see the deploy report; known failures only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes |
| Version | `VERSION` file bumped to `2026.11.12.6` |

## What's in it
Owner 2026-11-12: "go ahead with restaurant delivery service and Petpooja connection" — owner decisions: delivery fee
from the restaurant's **prepaid 1CallFix balance**, **prepaid food only** (rider never collects food money),
**parcel prices**.
- **Reminder before a waiting order is cancelled:** a paid food order not accepted after
  `food.nudge_after_minutes` (Food Settings → Orders, default 2, 0 = off) goes to the owner once — partner-app push
  plus the platform channels (email / SMS / WhatsApp when on). Runs inside `food:expire-unaccepted` (every minute).
- **Printable kitchen slip (KOT):** "🖨 Print kitchen slip" on every order in the seller portal →
  `/seller/orders/{order}/slip` (owner only), sized for a 58/80 mm receipt printer.
- **Book a rider for your own order:** button on the seller Home and Orders (only while Parcel is on somewhere and the
  shop has a map point) → Send a parcel with `?from=store`: pickup = the shop, contents = food, pay from the 1CallFix
  balance ("Add money to your balance" link), no cash on delivery. Everything else is the normal parcel flow (riders,
  tracking, prices).
- **Petpooja / UrbanPiper:** not connected yet — their order formats are given only to signed-up partners. No code
  guessed. Next step is the owner's partner sign-up (see the deploy report).

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_13_130000_add_accept_nudged_at_to_marketplace_orders` (plus part B's
  `2026_11_13_120000_partner_document_lists_per_business` if part B is not live yet)

Rollback count for `migrate:rollback --step=`: `1` (or `2` with part B)

## Step 10 — scheduled commands that must be listed
- `food:expire-unaccepted` (already listed; now also sends the reminder)

## Browser checks after deploy
- Seller portal (a restaurant): "Book a rider for your own order" on Home and Orders; "Print kitchen slip" on an order.

## Admin settings to do after deploy (owner)
- Optional: Food Settings → Orders → "Remind the restaurant about a waiting order after" (default 2 minutes).
