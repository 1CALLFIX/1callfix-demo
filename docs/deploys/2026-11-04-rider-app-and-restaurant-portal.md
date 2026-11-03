# Release 2026-11-04 — Rider app for every job, restaurant portal polish

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | `6ff4fa8` (VERSION 2026.11.04.1) |
| Full test suite | 2026-11-04, folder by folder: 4484 tests, all pass except the known `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env, unrelated) |
| Composer | no |
| Migrations | none |
| Front-end build (steps 6–7) | **yes** (new colours for the job labels and the cover photo) — CSS `app-DIc_V1qk.css` |
| Worker restart (step 9) | yes |
| Version | `2026.11.04.2` |

## What's in it (owner 2026-11-03: "the rider is the same for parcel, shop and food, so he should manage all deliveries")
**Rider app (/rider)**
- One **Jobs** screen runs everything: parcel, shop and food deliveries and shop return pickups. Every offer, the
  current job and every history line carry a coloured label: **Parcel** (blue), **Shop** (violet), **Food** (orange),
  **Return pickup** (grey). Food jobs say "restaurant" (Collect from the restaurant, Call the restaurant, cooking time).
- One job at a time across all kinds (as before); while carrying one, no other offers show.
- **One cash-dues figure** on Jobs and Earnings (parcel dues + shop/food cash to hand back). Before, Home showed only
  parcel dues (₹12 vs ₹326 in the owner's screenshots).
- **History** lists every kind of finished job with what the rider earned (the food delivery was missing before).
- An app-style **bottom bar** (Jobs · History · Earnings) like the customer app; Online/Offline pill and a ⋯ menu
  (switch to partner mode, sign out) at the top. The separate Shop tab is gone (/rider/shop still works for old links).

**Restaurant portal (/seller)**
- A restaurant's page says "restaurant" everywhere (mobile number, about, address, save button, menu search).
- **Cover photo** upload; customers see it wide on top of the card on the Food page and on the menu header.
- Opening hours with none set read "No set hours" with a note, not "Closed" on every day.
- Earnings: "On hold until 6:09 PM" (time, not just the date) and "problem-report time" instead of "return window".
- Home: "N sold out today" under Dishes, and "N problems waiting for you — Agree or Dispute" linking to Problems.

## Step 5 — migrations
- none

## Step 10 — scheduled commands
- none new

## Browser checks after deploy
- Rider phone → /rider: bottom bar Jobs · History · Earnings; one cash-dues figure (same as Earnings); History shows
  the food delivery NLR-MKT-0311-00000002 with an orange Food label.
- A new test food order marked ready: the offer on Jobs has the orange Food label; accept, deliver with the code.
- test.restaurant → Restaurant: restaurant wording, Cover photo upload; upload one, then /nellore/food shows it.
