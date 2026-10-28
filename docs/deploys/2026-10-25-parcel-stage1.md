# Release 2026-10-25 — parcel Stage 1

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `2c0d115` (separate dispatch queue, 2026-10-25, deploy that first). If it is not live yet, deploy it first, then this |
| Full test suite | 4064 of 4065 pass. Only failure: the known `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local `.env`) |
| Composer | composer.lock changed: no |
| Front-end build (steps 6–7) | yes (new Tailwind classes on the parcel, rider and admin pages) |
| Version | `VERSION` file bumped to `2026.10.25.2` (second release today) |

## What's in it
Parcel Stage 1, from the Borzo / Shiprocket study (owner go-ahead 2026-10-25). Parcel stays Inactive until the
owner switches it on in Modules; nothing changes for services.
- **Now | Schedule.** The customer can pick a pickup time. A scheduled parcel has its own base fare
  (`parcel.schedule_base_fare`; blank = no Schedule option). It is a separate price, not a discount, so any
  payment method works. It is paid at booking and offered to riders shortly before the pickup time by the new
  `parcel:release-scheduled` command (every minute).
- **Waiting charge at pickup.** The rider taps "I've arrived" (only within `parcel.arrival_radius_km` of the
  pickup pin, from live GPS). After the free minutes, each started block is charged. The sender pays the rider
  directly; it is not in the delivery price, the commission or the rider's cash dues. Blank settings = off.
- **Contents + restricted items.** Contents chips (optional, shown to the rider). Every booking channel (web, API,
  admin call-centre) requires the sender to confirm none of the restricted items; the time is recorded.
- **Recipient SMS** now names the rider and gives their phone.
- **Vehicle** on riders and orders (two-wheeler only for now); a rider is only offered parcels for their vehicle.
- **Parcel Dashboard** (Admin → Other Verticals → Parcel Dashboard, `parcel_orders.view`, row-scoped): orders
  today, parcels waiting for a rider (oldest first), average time to a rider, scheduled pickups ahead, riders
  online, rider cash dues, and the franchise parcel share (Super Admin, franchise owner, or `commissions.view`).
- API: `POST /api/parcel-orders` now requires `restricted_items_confirmed: true`; accepts `package_contents`,
  `delivery_type` (now|scheduled), `scheduled_at`. The quote returns `schedule_available`, the contents options and
  the restricted list. No published app calls this yet.

## Step 5 — migrations that must be pending (exactly these)
- `2026_10_25_100000_parcel_stage1` (adds columns to `parcel_orders` and `field_workers`; no enum widened)

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- `parcel:release-scheduled` (every minute)
- `parcel:expire-unpaid` (still every 5 minutes)

## Browser checks after deploy
- Admin → Other Verticals → **Parcel Dashboard** opens (zeros are fine while parcel is Inactive).
- Admin → **Parcel Settings** shows the new groups "Scheduled delivery" and "Waiting charge", all blank except the
  defaults in their help text.
- Admin → Parcel Orders → Add New shows the restricted-items tick.
- `/parcel` still 404 while parcel is Inactive everywhere; home has no Send-a-parcel tile.

## Admin settings to do after deploy (owner)
Only when you are ready to launch parcel in a city (see the parcel delivery note of 2026-10-23 first):
- Parcel Settings → Scheduled delivery → **Base fare, scheduled parcel** (blank keeps Schedule off).
- Parcel Settings → Waiting charge → **Free waiting time** and **Charge per block** (blank keeps it off).
