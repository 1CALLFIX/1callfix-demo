# Release 2026-11-12 — Partners part B: the right documents, progress, messages, help, terms

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `0a2400c` |
| Full test suite | see the deploy report; known failures only: `NotificationCenterAuditTest` (.env) |
| Composer | no |
| Front-end build (steps 6–7) | yes (Go live progress bar, Partner documents screen) |
| Version | `VERSION` file bumped to `2026.11.12.5` |

## What's in it
Owner 2026-11-12, part B of "join in 2 minutes":
- **A document list per business.** Hotels: photo ID, property proof, bank details (+ trade/hotel licence, GST if any).
  Restaurants: photo ID, FSSAI licence, bank details (+ GST, trade licence). Shops: photo ID, bank details (+ GST, shop
  licence). Service providers and riders keep their lists. A partner in several kinds needs every list (merged).
  Sign-up, "Go live" and the approval check all use it. **Admin → Website & Pages → Partner documents** (Super Admin):
  rename, required / if you have it, on / off, add a document.
- **"Go live" progress bar** ("2 of 4 done"); step 3 ticks once customers would see something (a room with a price, a
  product or dish, services ticked).
- **Messages** (email / app, through the notification channels): "Welcome — go live" after applying, "We have your
  documents" when the last one arrives, "Please upload a document again" with the reason when the team rejects one,
  and "You are approved". Joining messages carry a "Go live" button.
- **"Call me, help me go live"** on Go live → Admin → Partner leads as a new lead marked **"Asked for a call"**
  (once a day).
- **Terms on /partners per business:** Admin → Partner page → role_cards JSON `"terms"` (max 140), e.g.
  `{"hotel":{"terms":"Commission 10% · Paid every week · No joining fee"}}`. Blank = not shown.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_13_120000_partner_document_lists_per_business` — widens `kyc_document_requirements.applicable_type` from
  enum('provider','field_worker') (read on production with SHOW COLUMNS 2026-11-12: exactly these two) to string(30),
  and adds the hotel / restaurant / shop lists (12 existing rows untouched).

Rollback count for `migrate:rollback --step=`: `1`

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- Admin → Partner documents: tabs for Service providers, Delivery riders, Hotels and stays, Restaurants, Shops.
- A hotel partner's Go live: property proof listed, no skill certificate; progress bar.

## Admin settings to do after deploy (owner)
- Check the three new lists in Admin → Partner documents (wording, what is required).
- Optional: write each role's terms in Admin → Partner page → role_cards.
