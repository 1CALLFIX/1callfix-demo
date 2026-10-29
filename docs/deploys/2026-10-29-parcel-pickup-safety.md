# Release 2026-10-29 — Parcel pickup safety (banned items)

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the merge commit that adds this file |
| Live before | `a28dcf0` (Commerce switchable on Modules) |
| Full test suite | see the merge commit; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | **yes** (new rider / admin / booking-page markup) |
| Version | `VERSION` file bumped to `2026.10.29.6` |

## What's in it
Owner question: how to stop drugs and other banned items going through parcel. Owner decisions 2026-10-29:
photos are **not** taken on every parcel — only when the rider has a doubt; the sender must cooperate.

- **Rider (web `/rider` and the rider API):** a "Something not right?" panel on the current job.
  - *Safety photo* (optional): tick what looks unusual (the sender, the pickup place, the item, the packing, the
    pickup time, something else), choose "Item, before packing" / "Parcel, after packing", take the photo, add a note.
    Allowed from acceptance until delivery, up to 10 per parcel. Only admins see the photos; the sender is not told.
  - *Refuse parcel* (before pickup only): it holds a banned item / the sender would not let me check or photograph it /
    something else is not right (note required). The rider must be at the pickup point (live location, same radius
    as "I've arrived"). The order is cancelled, the sender is told, admins are emailed.
  - After pickup there is no refuse button: the screen says stop, don't open it further, call support.
- **Money** (Parcel Settings → Pickup safety, both unset = nothing happens):
  - `parcel.refusal_fee`: kept from the sender's online / wallet refund, only for "banned item" or "would not let me
    check". Never for "something else", never collected on a cash order.
  - `parcel.refusal_rider_pay`: credited to the rider's wallet for the wasted trip (wallet label "Trip pay (refused parcel)").
- **Banned items list** is now edited on Parcel Settings (one per line, per global / franchise / zone). Never saved =
  the same six items as before. Shown on the booking page, the call-centre form and the app quote.
- **Booking page:** under the banned-items tick, the sender is told the rider may check and photograph the item
  before and after packing, and may refuse it if not allowed (with the fee, if one is set).
- **Admin → Parcel Orders:** new "Flagged" tab (safety photo or refusal), a red note on the row, and a "Pickup
  safety" card on the order with the reason, the rider's note, where the rider was, the fee, the trip pay and the
  photos (streamed from the private disk, `parcel_orders.view` at the order's area only).
- **Alert email** "Parcel refused at pickup by the rider" (Admin → Alert Emails, on by default) to admins holding
  `parcel_orders.view` for that area.
- Photos are stored in `storage/app/private/parcel-safety/{order}/`.

## Step 5 — migrations that must be pending (exactly these)
- `2026_10_29_600000_add_parcel_pickup_safety`

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Admin → Parcel Settings: a "Pickup safety" group (two blank values) and a "Banned items" card listing the six standard items under "In force here now".
- Admin → Parcel Orders: a "Flagged" tab (empty).
- `/parcel` (signed in as a customer): under "My parcel has none of these items", the new sentence about the rider's check and photos.

## Admin settings to do after deploy (owner)
- Optional: refusal fee and rider trip pay (Parcel Settings → Pickup safety). Leave blank for none.
- Optional: edit the banned items list.
- Walk-through with the test logins (`php artisan test-accounts create --password=...` on the server, if not done):
  as the test customer book a parcel where parcel is active; as `test.rider` accept it, open "Something not right?",
  save one photo, then refuse it as "something else" with a note. Check Parcel Orders → Flagged shows the photo and
  the reason, and the alert email arrives.
