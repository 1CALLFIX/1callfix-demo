# Release 2026-11-17 — Franchise page (costs, revenue, Insights) and property listing fee

Use with `docs/DEPLOY_RUNBOOK.md`.

| | |
|---|---|
| Deploys | `main` at the commit that adds this file (`git log -1` in runbook step 1) |
| Live before | `<hash currently on the server>` |
| Full test suite | Related files 32/32 (revenue section 5, Franchises 3+, ContactAndFranchisePages, ShareButton: 27 passed together). Full suite NOT yet run on this machine (memory): run it before step 1. |
| Composer | no (`composer.lock` unchanged) |
| Front-end build (steps 6–7) | no (Blade only, Alpine already bundled) |
| Version | `VERSION` 2026.11.17 |

## What's in it
- `/franchise`: "What it costs on your own" now shows practical planning ranges (₹3–12 lakh per business, plus monthly running cost; total ₹30–59 lakh) instead of ₹66–96 lakh.
- New "What your city can earn" section: eight revenue rows (orders × average order × commission + per-order extra), three tap buttons (40 / 70 / 100 %), total, running-cost line. All figures are Super Admin fields (Admin → Franchise page → Revenue potential). Royalty (owner 2026-11-17: 8% of the total income the franchise earns, not of order value) shows by default; emptying `rev_royalty_pct` hides it.
- Admin → Finance → **Franchise Insights** (permission `commissions.view`, same scope as Franchise Earnings): bookings placed / completed / cancelled, average job value, active / new / repeat customers, active partners, top services, 14-day trend, each against last month. Super Admin switches blocks on or off for owners on the Franchise page screen. Home-service bookings only today.
- **Property listing fee** (Admin → Rental Settings → "Property for rent · Listing fee", per city or zone; default ₹0 = free, nothing changes until set): with a fee, approving a property no longer puts it live; the owner pays on their property screen **online (Razorpay)** or **from the wallet** and it runs for the listing days; renewing needs payment, not a tap on "Still available". Optional "show it first" fee (homes.featured_fee, homes.featured_days); homes:tidy ends lapsed features. Fees live in a new listing_fees table (the payments.purpose enum is NOT widened); the Razorpay webhook checks the exact amount and activates once.
- Migration swaps the saved "on your own" wording only where it is still our original; edited text is untouched.

## Step 5 — migrations that must be pending (exactly these)
- `2026_11_17_120000_franchise_page_practical_costs` (data only, no schema change)
- `2026_11_17_130000_create_listing_fees_table` (new table `listing_fees` + column `house_listings.featured_until`; both additive)

Rollback count for `migrate:rollback --step=`: 2 (the cost-text migration's down is a no-op; the listing-fee one drops its table and column)

## Step 10 — scheduled commands that must be listed
- none new

## Browser checks after deploy
- `/franchise`: new cost cards, "What your city can earn" with three buttons; totals ₹2,31,500 on "Our target", ₹92,600 on "Just starting", Royalty 8% line shown; phone width.
- Rental Settings shows the three new listing-fee settings. With the fee at 0, approving a test property still puts it live. Then set a small fee on a test zone: approve a property, see "Pay online" and "Pay from wallet" on the owner screen, pay from a funded test wallet, and see it go live. Pay one online payment in Razorpay test mode if the gateway is in test mode (webhook activates it).
- Admin → Finance → Franchise Insights opens for a franchise owner and shows only their franchise.

## Admin settings to do after deploy (owner)
- Royalty base decided: income earned. Put the same wording in the franchise agreement.
- Property rentals set to 8 lettings a month (owner: 5-10).
- Listing fee is OFF (₹0) until you set it: pick a fee per city or zone in Rental Settings when ready.
- Clear "₹30–59 Lakhs" total on the cost section if you prefer no seven-business total.
- No test login needed: no new app or role.
