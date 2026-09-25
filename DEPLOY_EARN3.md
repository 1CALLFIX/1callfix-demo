# DEPLOY_EARN3 — Earnings (leak fixes + Rule of Law controls + customer Earnings)

REF 1CF-PROMPT-20260925-QA3 · Branch `feature/customer-earnings` · Tip: the commit after `2634db0`
(check `git log -1 --oneline` on your machine before you start; write the SHA down).

**Nothing in this file has been run against production.** Every command below is for you to run,
in order. Server facts come from `docs/DEPLOYMENT_RUNBOOK.md` and `docs/ROLLBACK_PLAN.md`:
user `callf1207`, host `srv1422426.hstgr.cloud` (also `31.97.186.175`), path
`/home/1callfix.com/public_html/api/`, database `1cal_api`, queue worker `onecallfix-worker:*`.

> **The one thing that can bite you:** since this release, every Earnings setting is
> *unset = OFF*. If you deploy before saving the values in Step 1, **wallet payments, wallet
> top-up and loyalty earning/redemption stop working** the moment the code goes live.
> Do Step 1 first. Do not skip it.

**Scope:** this pack deploys `feature/customer-earnings` only (EARN3). It does **not** include
`feature/referral-capture` (EARN4). Referral rewards stay off; leave `earnings.referral_tab` OFF.

---

## 0. Before you start (on your PC)

The branch has never been pushed. Push it (this is the only way the server can fetch it):

```bash
git push origin feature/customer-earnings
```
Expected: `* [new branch]      feature/customer-earnings -> feature/customer-earnings`.
If it is rejected, stop — do not force-push.

Build the assets now (needed in Step 6), on Node 22:

```bash
nvm use 22
npm ci
npm run build
```
Expected: ends with `✓ built in …` and creates `public/build/manifest.json`.
(Takes ~2–3 minutes on this PC.)

---

## 1. Production settings — read first, then save the missing values

Run on the **production** database (read-only `SELECT`), e.g. in phpMyAdmin or
`mysql -u <db_user> -p 1cal_api`:

```sql
SELECT scope_type, scope_id, `key`, value, updated_at
FROM settings
WHERE `key` IN (
  'earnings.enabled','earnings.wallet_tab','earnings.loyalty_tab','earnings.referral_tab',
  'wallet.topup_enabled','loyalty.customer_enabled','loyalty.provider_enabled','loyalty.redeem_enabled',
  'referral.enabled','referral.max_per_customer','wallet.admin_adjustment_max','loyalty.admin_adjustment_max',
  'earnings.flag_refund_above','earnings.flag_referrals_above',
  'wallet.customer_min_topup','wallet.customer_max_topup','wallet.customer_max_balance',
  'wallet.customer_daily_topup_limit','wallet.customer_monthly_topup_limit',
  'payment.online_enabled','payment.wallet_enabled',
  'loyalty.customer_points_per_currency_unit','loyalty.provider_points_per_completed_job',
  'loyalty.points_per_rupee_redemption','loyalty.min_redemption_points','loyalty.points_expiry_days',
  'referral.reward_type','referral.reward_amount','referral.reward_points','referral.pending_expiry_days'
)
ORDER BY `key`, scope_type, scope_id;
```

**What to expect:** a handful of rows at most (any key an admin ever saved). Most keys will be
missing — that is normal.

### Rule for each missing key (save these in today's admin, BEFORE deploy)

The **old** admin screens display these values when a key is unset, so opening the tab and
pressing **Save** stores them. The numbers are what the old code was silently using — reference
only; use different numbers if you want different behaviour.

| Admin screen (today) | Save these keys | Old in-code value | If already present |
|---|---|---|---|
| Settings → Payment | `payment.wallet_enabled` = Enabled | `1` | keep whatever it is |
| Settings → Wallet | `wallet.customer_min_topup` | 100 | keep |
| | `wallet.customer_max_topup` | 10000 | keep |
| | `wallet.customer_max_balance` | 50000 | keep |
| | `wallet.customer_daily_topup_limit` | 20000 | keep |
| | `wallet.customer_monthly_topup_limit` | 100000 | keep |
| Settings → Loyalty / Referral | `loyalty.customer_points_per_currency_unit` | 0.01 | keep |
| | `loyalty.points_per_rupee_redemption` | 10 | keep |
| | `loyalty.min_redemption_points` | 100 | keep |
| | `loyalty.points_expiry_days` | 365 (0 = never expire) | keep |
| | `loyalty.provider_points_per_completed_job` | 5 — **optional**, only if you want provider points | keep |
| Settings → Payment | `payment.online_enabled` | `1` | keep (still defaults on; not changed here) |

Referral keys (`referral.*`): **do nothing** — the referral engine cannot be reached by real
customers in this release.

**Then re-run the SELECT.** You should now see every key above with a value.
**If a key is still missing → do not deploy.** Save it, re-run, repeat.

**Keys that don't exist yet and that you set AFTER deploy (Step 10):** `earnings.*`,
`wallet.topup_enabled`, `loyalty.customer_enabled`, `loyalty.provider_enabled`,
`loyalty.redeem_enabled`, `wallet.admin_adjustment_max`, `loyalty.admin_adjustment_max`,
`earnings.flag_*`. They have no screen before this release, so they are created in
Earnings Control after the deploy.

---

## 2. Backup the production database

```bash
ssh callf1207@srv1422426.hstgr.cloud
mkdir -p ~/backups
mysqldump -u <db_user> -p --single-transaction --routines 1cal_api > ~/backups/1cal_api_pre_earn3_$(date +%Y%m%d_%H%M%S).sql
ls -lh ~/backups/
```
**Expected:** a new file `1cal_api_pre_earn3_YYYYMMDD_HHMMSS.sql`, **non-zero size, about the same
as your last dump**. Check the last lines:

```bash
tail -n 3 ~/backups/1cal_api_pre_earn3_*.sql
```
Expected last line: `-- Dump completed on YYYY-MM-DD HH:MM:SS`.
**If the file is 0 bytes, much smaller than the last one, or lacks "Dump completed" → stop.**
Do not deploy without a good backup.

Write down the current production commit (your rollback target):

```bash
cd /home/1callfix.com/public_html/api/
git log -1 --oneline        # write this SHA down: ROLLBACK_SHA
git status --short          # expected: nothing (a clean checkout)
```
If `git status` shows local changes, stop and tell me.

---

## 3. Get the code onto the server

**Method: `git fetch` + checkout on the server.** The runbook (§2) and the rollback plan both assume
the server is a git checkout of this repo (`git pull`, `git reset --hard <sha>`), and the last
deploy worked that way. It also gives an exact SHA to roll back to. The `git archive` tarball
method (runbook §2, step 2 alternative) is the fallback if git on the server is broken.

```bash
cd /home/1callfix.com/public_html/api/
php artisan down --secret="$(openssl rand -hex 16)" --render=errors::503
```
**Expected:** `INFO Application is now in maintenance mode.` and a secret URL — keep it, it lets you
test while the site is down.

```bash
git fetch origin feature/customer-earnings
git checkout feature/customer-earnings
git log -1 --oneline
```
**Expected:** `Switched to branch 'feature/customer-earnings'`, and the SHA matches the one from your PC.
**If checkout complains about local changes → stop.** If `fetch` says the branch doesn't exist →
you skipped Step 0's push.

---

## 4. PHP dependencies

```bash
composer install --no-dev --optimize-autoloader
```
**Expected:** `Installing dependencies from lock file`, no errors, ends with
`Generating optimized autoload files`. `brianium/paratest` and other dev packages are **not**
installed (correct). **If it errors → stop** (do not migrate).

---

## 5. Migrations

Preview first — this writes nothing:

```bash
php artisan migrate --pretend
```
**Expected — exactly these three migrations, with this SQL (MariaDB):**

```
2026_09_25_001000_add_ref_and_actor_to_loyalty_points_table
  alter table `loyalty_points` add `ref` varchar(255) null after `booking_id`;
  alter table `loyalty_points` add `actor_id` bigint unsigned null after `ref`;
  alter table `loyalty_points` add constraint `loyalty_points_actor_id_foreign` foreign key (`actor_id`) references `users` (`id`) on delete set null;
  alter table `loyalty_points` add unique `loyalty_points_ref_unique`(`ref`);
2026_09_25_002000_add_frozen_fields_to_wallets_table
  alter table `wallets` add `frozen_at` timestamp null after `balance`;
  alter table `wallets` add `frozen_reason` varchar(500) null after `frozen_at`;
  alter table `wallets` add `frozen_by` bigint unsigned null after `frozen_reason`;
  alter table `wallets` add constraint `wallets_frozen_by_foreign` foreign key (`frozen_by`) references `users` (`id`) on delete set null;
2026_09_25_003000_add_actor_id_to_wallet_transactions_table
  alter table `wallet_transactions` add `actor_id` bigint unsigned null after `ref`;
  alter table `wallet_transactions` add constraint `wallet_transactions_actor_id_foreign` foreign key (`actor_id`) references `users` (`id`) on delete set null;
```
All three are additive and nullable — no data is changed or dropped.

**If other migrations also appear** (older ones production never received): stop and send me the
list before continuing. **If any statement is `drop`, `rename` or `truncate`: stop.**

```bash
php artisan migrate --force
```
**Expected:** three lines, each `… DONE`. Then:

```bash
php artisan migrate:status | tail -5
```
Expected: the three `2026_09_25_*` migrations show `Ran`.
**If `migrate --force` fails midway →** go to Rollback §12 ("Migration failed").

---

## 6. Assets

Assets are built on your PC (Step 0); the server's own `npm run build` is unreliable
(see runbook). From your PC:

```bash
scp -r public/build callf1207@31.97.186.175:/home/1callfix.com/public_html/api/public/
```
**Expected:** a list of files transferred, no errors. Then on the server:

```bash
ls -la /home/1callfix.com/public_html/api/public/build/manifest.json
```
Expected: the file exists and its timestamp is **now**. No cache-bust needed (`@vite` reads the new
manifest). **If the new pages look unstyled later → the scp didn't finish; repeat it.**

---

## 7. Caches and worker

```bash
cd /home/1callfix.com/public_html/api/
php artisan event:clear && php artisan event:cache
php artisan config:cache
php artisan route:cache
php artisan view:cache
```
**Expected:** each prints `INFO … cached successfully.` (or `Events cached successfully.`).
`view:cache` compiles every Blade file — **if it reports a Blade syntax error, stop** and tell me.

Restart the queue worker so it loads the new code:

```bash
sudo supervisorctl restart onecallfix-worker:*
supervisorctl status onecallfix-worker:*
```
Expected: `RUNNING`, start time **after** now. (Only `onecallfix-worker` — leave `queue_manager` and
`laravel_reverb` alone; they belong to a different app.)

Bring the site back:

```bash
php artisan up
```
Expected: `INFO Application is now live.`

---

## 8. Check the scheduler cron exists

The new `loyalty:expire-points` command runs daily through Laravel's scheduler, which only works if
the standard cron line exists.

```bash
crontab -l
```
**Expected:** a line like
`* * * * * cd /home/1callfix.com/public_html/api && php artisan schedule:run >> /dev/null 2>&1`.

**If missing** (the L-01 dispatch sweep and every other scheduled task also depend on it — so this
matters beyond Earnings):

```bash
which php                       # note the path, e.g. /usr/local/lsws/lsphp83/bin/php
crontab -e                      # add, using that php path:
* * * * * cd /home/1callfix.com/public_html/api && /path/to/php artisan schedule:run >> /dev/null 2>&1
```
Then confirm it registered: `php artisan schedule:list` — expected to include
`loyalty:expire-points` (daily), `referrals:expire-due`, `dispatch` sweep, etc.
Check **Admin → Operations → Scheduled tasks** after a few minutes: "last run" should update.

---

## 9. Post-deploy audits (read-only)

```bash
php artisan loyalty:balance-audit
php artisan bundles:refund-audit
php artisan earnings:settings-report
```
**Expected:**
- `loyalty:balance-audit` → `No loyalty balance discrepancies found.` (exit 0). If it lists users
  (`old=… fifo=…`, possibly negative old balances): these are **historical over-redemptions** from the
  old formula. Nothing is changed automatically. Save the output and send it to me.
- `bundles:refund-audit` → `No cancelled bundle child is missing a refund.` If it lists bundles: a
  child was cancelled without its refund. Send me the output; the safe repair is re-running settlement
  for that bundle (refunds only the missing amount, once).
- `earnings:settings-report` → a table. Every key from Step 1 shows its value; the new keys show
  `UNSET` (expected until Step 10).

---

## 10. Turn the features on (Admin → Finance → Earnings Control, as Super Admin)

Do these **in order**, and stop at the first thing that looks wrong:

1. **Switches & limits → `earnings.enabled` = ON**, then **`earnings.wallet_tab` = ON**.
   Customers now see Earnings → Wallet (their balance and history).
2. **`wallet.topup_enabled` = ON** — restores customer top-up (needs all five top-up limits from Step 1).
3. **`loyalty.customer_enabled` = ON** — customers earn points again.
4. **`loyalty.redeem_enabled` = ON** and **`earnings.loyalty_tab` = ON** — redemption and the Loyalty tab.
5. *(Optional)* **`loyalty.provider_enabled` = ON** — provider points. Providers can never redeem them.
6. **Limits:** set `wallet.admin_adjustment_max` and `loyalty.admin_adjustment_max` if you want to allow
   admin corrections (blank = adjustments disabled). Optionally `earnings.flag_refund_above` for
   monitoring.
7. Leave `referral.enabled` and `earnings.referral_tab` **OFF/UNSET**.

Each switch change is written to the audit log (Admin → Operations → Activity log shows `old → new`).

Verify wallet payments still work: **Settings → Payment → Wallet = Enabled** (from Step 1).

---

## 11. 5-minute live smoke test (you click through)

Use your own test customer account (with a wallet balance ≥ ₹100) and your Super Admin.

1. **Customer site → Earnings → Wallet:** balance shown; history rows have readable labels
   ("Refund", "Paid from wallet", …), not raw codes.
2. **Add money** (small amount, e.g. the minimum): the Razorpay window opens. Cancel it — no charge.
   A pending top-up row is normal.
3. **Earnings → Loyalty points:** figures shown; the "How points work" text is readable (no `@if`).
   If you have ≥ the minimum points: **Review** shows points and ₹ → **Confirm** → wallet shows
   "Loyalty points redeemed".
4. **Nothing anywhere shows one combined "money + points" total.**
5. **Book a service paying from wallet** (or open the booking form): "Wallet" is offered as a payment method.
6. **Admin → Earnings Control:** every tab loads. Freeze **your test customer** with a reason → on the
   customer site the wallet shows "on hold" and Add money disappears → **Unfreeze** with a reason.
7. **Admin → Operations → Activity log:** the freeze/unfreeze and your switch changes show reasons and
   `old → new`.
8. **Log in as a non-super-admin admin (settings.manage only):** Earnings Control → 403.
9. Watch `tail -f storage/logs/laravel.log` during the above — no new `ERROR` lines.

**Any failure →** Rollback §12.

---

## 12. Rollback

Choose the smallest one that fixes it. **The three new columns are nullable and unused by old code,
so rolling back the code alone is safe and does not require touching the database.**

### A. Code only (most cases)
```bash
cd /home/1callfix.com/public_html/api/
php artisan down --secret="$(openssl rand -hex 16)"
git checkout <ROLLBACK_SHA>            # the SHA you wrote down in Step 2 (or: git reset --hard <ROLLBACK_SHA> on the checked-out branch)
composer install --no-dev --optimize-autoloader
php artisan event:clear && php artisan event:cache
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo supervisorctl restart onecallfix-worker:*
php artisan up
```
Also restore the assets from the previous build if the old pages look wrong. After this, old code
runs with the Step 1 settings you saved (they are all still valid for it). Known issue you are
returning to: the negative-loyalty-balance and bundle-refund collision bugs.

### B. Migrations (only if a migration failed, or you must remove the columns)
Only after code is rolled back (A). Check they are the newest batch first:
```bash
php artisan migrate:status | tail -6
php artisan migrate:rollback --step=3 --force
```
Rolls back, in this order: `…003000_add_actor_id_to_wallet_transactions_table`,
`…002000_add_frozen_fields_to_wallets_table`, `…001000_add_ref_and_actor_to_loyalty_points_table`.
**Warning:** this drops `wallet_transactions.actor_id`, `wallets.frozen_*` and
`loyalty_points.ref`/`actor_id`. If admins have already made adjustments/freezes or the expiry job has
written `loyalty-expire:*` rows, that data is lost — in that case prefer **A only**.
**Migration failed midway:** run `php artisan migrate:status`; fix or roll back only the migration
that failed; if unsure, use C.

### C. Database restore (last resort — loses everything written since the backup)
```bash
php artisan down --secret="$(openssl rand -hex 16)"
mysql -u <db_user> -p 1cal_api < ~/backups/1cal_api_pre_earn3_YYYYMMDD_HHMMSS.sql
php artisan up
```
Then do **A** so code and schema match. Any customer payments, wallet movements or bookings made
after the backup must be reconciled by hand (Admin → Payments, Wallet Ledger).

### After any rollback
`php artisan migrate:status`, then the smoke test items 1, 5 (customer wallet page and wallet payment)
against the old code; and note what failed and send it to me.
