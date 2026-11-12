# Release 2026-11-13 — Emergency override (services, parcel, food/shop) + app buttons in the footer bar

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `1714803` |
| Full test suite | see the deploy report; known failures only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (footer layout) |
| Version | `VERSION` file bumped to `2026.11.13` |

## What's in it
Owner 2026-11-12: Super Admin and franchise owners can assign directly and change any order status in an emergency,
when the provider / rider / shop / restaurant cannot, and it is recorded in the logs.
- New permission `orders.emergency_override` (Super Admin always; given to every role that already had
  `bookings.reassign` or `parcel_orders.reassign`). Checked against the order's own franchise.
- **Services** (Bookings → booking): "Emergency: update for the professional" → Mark started / Mark completed.
- **Parcel** (Parcel orders → order): "Emergency: update for the rider" → Mark picked up / Mark delivered
  (single-drop parcels; multi-drop / return trips are finished by the rider or cancelled).
- **Food / shop** (Marketplace orders → order): Accept / Preparing / Ready / Collected / **Mark Delivered**, and
  **Assign now** next to Ring (rider gets the order without accepting). These buttons now need the new permission
  (before, "view orders" was enough).
- Every change needs a written reason; the order history shows the admin's name + reason and the activity log has an
  "Emergency override: …" entry. Payment, commission, cash dues and notifications run as normal.
- Admin rider/provider assignments now record the admin's name in the history.
- Customer footer: Google Play / "Use on iPhone" buttons moved into the bottom bar.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_12_100000_seed_orders_emergency_override_permission`

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Footer (any customer page, computer width): app buttons in the bottom bar, nothing under the logo.
- Admin → a booking in assigned / in progress: "Emergency: update for the professional" card shows.
- Admin → Food orders → an open order: reason box above the status buttons.

## Admin settings to do after deploy (owner)
- Roles: give "Emergency override" to any franchise role that should change food/shop order statuses and does not
  already reassign bookings or parcels.
