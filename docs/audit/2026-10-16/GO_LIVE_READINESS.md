# Go-live readiness — 2026-10-16

Read-only. Evidence: live-site HTTP checks, read-only SSH + `artisan` reads on production (`srv1422426`, HEAD
`bed4eaf`), local `main` `6b8bcf2`, audits `docs/audit/2026-09-22/` and `docs/audit/2026-10-15/`.

## Production snapshot (verified 2026-10-15/16)

| Item | Value |
|---|---|
| Env / debug / maintenance | production / OFF / OFF |
| Server HEAD | `bed4eaf` (L4 `6b8bcf2` pushed, not deployed) |
| Pending migrations | none |
| Cron | `schedule:run` every minute; 15 scheduled tasks listed |
| Queue worker | running (`queue:work --tries=3 --max-time=3600`); 1 old failed job (2026-07-30) |
| Errors in laravel.log since 2026-10-15 | 0 |
| DB backup | daily 02:00 → `storage/backups/*.sql.gz`, 14-day retention; manual dumps in `~/backups`. **Same server only.** |
| Disk | 16 GB used of 99 GB |
| Razorpay | **live** keys, webhook secret set |
| Push (FCM) | configured; 5 users hold a device token |
| Mail | SMTP, from-address set |
| Commission | global 30; Nellore HQ 10 / franchise 20 / provider 70; Guntur 1 inactive |
| Cash payments | **enabled** (`payment.cash_enabled = 1`) |
| `wallet.provider_min_balance_to_accept_jobs` | **0 = off** (no minimum) |
| Interim cap / visit fee | 40% / ₹149 |
| Coupons | off; provider plans: none |
| Providers | 3, all KYC approved, **0 online** |
| Bookings | 18 completed, 39 cancelled; last booking 2026-10-02 |
| Payments | 39 pending (₹26,411, abandoned checkouts), 2 captured, 2 failed, 1 refunded |
| Open cash commission dues | **16 receivables, ₹5,217.10** — provider 71: wallet ₹1,599.70, owes ₹2,618.20; provider 73: wallet ₹0, owes ₹1,499.40; provider 74: wallet ₹0, owes ₹1,099.50 |
| Public pages | home, services, city service, help, how-it-works, partners, offers, membership, terms, privacy, sitemap, robots: 200 in < 0.35 s |
| Missing pages | `/refund-policy`, `/cancellation-policy`, `/contact`, `/about`: 404; footer has no refund/contact link |
| Security headers | nosniff, X-Frame-Options present; no HSTS |

## A. Blockers (fix before public launch)

| # | Hurdle | Evidence | Fix |
|---|---|---|---|
| B1 | Cash commission dues have no limit and no way to pay (L3 + L8) | Cash is on; all 3 providers owe ₹5,217.10; two have ₹0 wallet and can keep taking cash jobs | Build L3 + L8 (`L3_L8_PLAN.md`); owner decisions pending: max dues, floor, withdrawable formula |
| B2 | No minimum wallet balance + low-balance providers still receive offers (L2, L1) | `wallet.provider_min_balance_to_accept_jobs` = 0 (off) on prod | Enter the value; L1 fix in the same `ProviderStanding` work |
| B3 | L4 (online jobs worked without payment) not on the server | Server HEAD `bed4eaf` | Deploy `6b8bcf2` (in progress, step 2) |
| B4 | Admin assign/reassign skips every eligibility check (L5) | Audit 2026-10-15 | Route admin assign through the same eligibility check, with a reasoned override |
| B5 | Payout "Mark paid / failed" outside the money approval model (L7) | CLAUDE.md rule breach | Put it under the approval model (permission, scope, limits, maker-checker, reason, audit) |
| B6 | Old production DB password still in git history | `scripts/backup-database.sh` now reads `.env`, history still holds the old value | Confirm the password was rotated; if not, rotate |
| B7 | Provider supply | 3 providers, all offline, one city | Onboard and activate providers before announcing |

## B. Should fix before launch

| # | Item | Fix |
|---|---|---|
| S1 | No refund/cancellation policy, contact or grievance-officer page | Add the pages (CMS) and footer links; needed for consumer e-commerce rules and the payment gateway |
| S2 | Suspended provider keeps getting offers ~30 min (L6); provider/staff suspend controls sit on unmerged `feature/admin-enable-disable-gaps` (`f4d8d12`) | Review vs `main`, merge what is still needed |
| S3 | Legacy non-bcrypt password crashes login with 500 — fix on unmerged `fix/bcrypt-verify-crash-on-legacy-password` (`80f11ed`) | Review and merge |
| S4 | Backups only on the same server | Copy the nightly dump off-server (another host / cloud bucket) |
| S5 | Test data in production (39 cancelled bookings, 39 pending payments, ₹5,217 dues) | Decide: keep, or settle/void before launch (the Clear Data tool is non-production only) |
| S6 | No HSTS header | Add `Strict-Transport-Security` once HTTPS-only is confirmed |
| S7 | Cash-job "earnings added to your wallet" message is inaccurate (provider keeps cash) | Copy fix |
| S8 | Referral capture (EARN4) unmerged `feature/referral-capture` (4 commits, 4 migrations) | Only if referrals are a launch feature; deploy caveats in its notes |

## C. Not verifiable from here (owner to confirm)

- DB password rotation (B6).
- Razorpay dashboard webhook URL points to the live site and a real ₹1 live payment + refund works end to end.
- Push received on a real provider phone with the app closed.
- Firebase phone-OTP authorised domains include `1callfix.com` and `www.1callfix.com`.
- SMTP mail actually delivered (inbox, not spam).

## D. Ready

Production env and debug off; no pending migrations; scheduler and worker running; daily backups; no new errors;
pages fast; Razorpay live with webhook secret; FCM and mail configured; commission split set by admin as intended;
interim cap and visit fee set; coupons and provider plans off.

## Suggested order

1. Finish the L4 deploy (B3).
2. Owner decisions for L3/L8, then build L3 + L8 + L1/L2 + L5 together (B1, B2, B4) and enter the minimum balance.
3. L7 under the approval model (B5).
4. Confirm/rotate DB password (B6); off-site backup (S4).
5. Refund/contact pages (S1); review and merge S2/S3.
6. Onboard providers (B7); decide on test data (S5); owner checks in C; then announce.
