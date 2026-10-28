# Release 2026-10-29 — E-commerce Phase 5 (tax compliance)

Use with `docs/DEPLOY_RUNBOOK.md`. Carries Phases 1–4 if they are not live yet (see their notes).

| | |
|---|---|
| Deploys | `main` at the commit that adds this file |
| Live before | check `git log -1` on the server |
| Full test suite | see the merge commit; known failure only: `NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default` (local .env) |
| Composer | no |
| Front-end build (steps 6–7) | yes |
| Version | `VERSION` file bumped to `2026.10.29.3` |

## What's in it
Owner 2026-10-28: build compliance now with the rates as Super Admin settings left unset; the CA fills them in.
These values are general knowledge, not checked against official sources — the CA must confirm every rate and rule.
- Seller GSTIN / PAN on Seller → Shop (format-checked), printed on the seller's **tax invoice** for every completed
  order (`/shop/orders/{order}/invoice`, printable / save as PDF) — open to the customer, the shop's seller and
  admins with `marketplace_orders.view`; linked from the customer order page and the seller's completed orders.
- **GST TCS** and **income-tax TDS (194-O)** withheld from the seller's goods share on the taxable value (before
  GST), stored per settlement (`tcs_gst_amount`, `tds_income_tax_amount`); a cash-at-pickup seller owes them back.
  Shop Settings → Tax compliance: both rates blank = nothing withheld.
- Admin → Shop Tax Report (Super Admin): per seller per month — GSTIN, PAN, orders, taxable value, TCS, TDS — and a
  CSV for the CA.
- Grievance officer details (Shop Settings, E-Commerce Rules 2020) shown on the shop home and the invoices.

## Step 5 — migrations that must be pending (exactly these)
- `2026_10_29_300000_add_shop_tax_compliance_columns`
- plus the Phase 1, 3 and 4 migrations if they ship together

## Step 10 — scheduled commands that must be listed
- none new

## Admin settings to do after deploy (owner, with the CA)
- Shop Settings → Tax compliance: GST TCS %, TDS 194-O %, grievance officer text.
- Confirm with the CA: registration as an e-commerce operator (GSTIN for TCS), monthly TCS return, 194-O
  thresholds, and the invoice format.
