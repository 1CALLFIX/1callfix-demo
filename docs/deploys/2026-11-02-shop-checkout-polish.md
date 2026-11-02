# Release 2026-11-02 — Shop checkout polish

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `4b54d9c` (City URLs) — stop if the server shows anything else |
| Full test suite | 4360 passed of 4361 (run in 12 chunks, all 424 files, 2026-11-02); known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | **no** — local build gives the same `app-BlckTjyE.css` already live (no new styles) |
| Version | `VERSION` file bumped to `2026.11.02.1` |

## What's in it
From the owner's live checkout walk-through 2026-11-02.

- **Discount shows.** Bag and checkout summary show the crossed-out price and "X% off · online" on a discounted item.
- **Cash says why it is off.** "Not available: <item> has an online-only price. Remove it or pay online." (or the
  coupon line). The thumb rule itself is unchanged: any discount or coupon = online only.
- **Own addresses only.** Checkout lists only the customer's own addresses, default first. Parcel send-to addresses
  are never listed or pre-selected, and are rejected server-side.
- **Add an address inside checkout**, with "Use my current location"; it is selected straight away.
- **Shop address** shown on delivery orders: "From <shop>, <address>".
- **Send to someone else**: receiver name + phone on the order (one migration). Shown on the customer's order page,
  the seller's Orders and the rider's delivery card (with a Call button). When a rider accepts, the receiver gets an
  SMS with the delivery code and the rider's name and number — only where SMS is a configured channel.
- New words in Website text & language → Shop checkout (English + Telugu).

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_02_100000_add_recipient_to_marketplace_orders` (two nullable columns, no data change)

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- none new

## Test logins and walk-through
`test.customer@1callfix.test` (and the Test Shop seller / test rider from `php artisan test-accounts`):
- `/nellore/shop` → add Test Discount Item + Test Product → Bag shows the crossed-out price and "20% off · online".
- Checkout: "From Test Shop, …"; only the customer's own addresses; Cash greyed with the item named; remove the
  discounted item → Cash is available.
- Add a new address inside checkout → it is selected.
- Tick "Send to someone else", enter a name + phone, place the order → order page shows "Receiver: …".

## Admin settings to do after deploy (owner)
- None required. Optional: Website text & language → Shop checkout to review the new Telugu words.
