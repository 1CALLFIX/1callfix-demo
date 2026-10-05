## Deploy rule
Code reaches the server only through git. Never copy source files, tarballs or
bundles to the server. The one exception is the compiled front-end,
`public/build` (git-ignored), because the server cannot run `npm run build`
(CyberPanel filesystem restriction).

Order, one step at a time, raw output reported for each. If a step fails, STOP
and report; do not work around it.

1. Push to origin (main). Full suite must pass first; only known failures
   listed in docs/ may remain.
2. Server: mysqldump of the production database to ~/backups. Report the
   file path and size. Do not continue if the size is 0 or the dump does not
   end with "-- Dump completed".
3. Server: `git fetch origin && git merge --ff-only origin/<branch>`.
   Stop if it refuses. HEAD must equal the hash you pushed.
4. Check whether composer.lock changed between the old HEAD and the new HEAD
   (`git diff --stat <old> HEAD -- composer.lock composer.json`). Say yes or
   no first. Only if yes: `composer install --no-dev --optimize-autoloader`.
5. `php artisan migrate:status`. Only the migrations you expect may be
   pending; if anything else is pending, STOP. Before widening any enum
   column, read the production enum (SHOW COLUMNS) and confirm it matches the
   migration's "current" list in full; a truncated paste is not a check.
   Then `php artisan migrate --force`. Migrate straight away: the new code is
   already on disk and may need the new columns.
6. Local build (the server cannot build): confirm every `VITE_FIREBASE_*`
   key in the local .env is present (key names only, never values; a
   `${...}` reference is fine, Vite expands it), then `npm run build`. Push
   notifications break without them. The local build uses whatever Node is
   installed (currently 24.19) until nvm is set up; nvm use 22 once it is.
7. Server: back up the current build first:
   `cp -a public/build ~/backups/public-build-<label>-<timestamp>`.
   Then, from the local machine, `scp -r public\build\* <user>@<host>:<app>/public/build/`.
   scp comes BEFORE the cache clear so the cache rebuild sees the new assets.
8. Server: four-phase cache clear, in this order:
   (1) `config:clear route:clear view:clear cache:clear event:clear`
   (2) `config:cache`  (3) `route:cache`  (4) `view:cache` and `event:cache`.
9. Restart the queue worker, and only the worker. The deploy user is not in
   sudoers, so exit to root, run
   `sudo supervisorctl restart onecallfix-worker:*` and
   `sudo supervisorctl status | grep onecallfix`, then `su - <deploy user>`
   to continue. Do not restart anything else.
10. `php artisan schedule:list` (a scheduled command you added must be
    listed), then browser checks on the live site and the tail of
    storage/logs/laravel.log (no new errors).

Never run git clean or npm audit fix --force on the server.
After every deploy, run git status and git log -1 on the server. The deploy is
not done unless the working tree is clean (only storage/ entries allowed) and
HEAD matches the hash you pushed. Report both.

## Standing business rules

### THUMB RULE — ONLINE PAYMENT ONLY FOR ALL BENEFITS
Only online payments get benefits. Online = Razorpay, wallet, or
wallet + Razorpay.
Benefits = every coupon type (promotional, referral, challenge, bulk,
auto-applied), every discount, offer, cashback, and any use of wallet
balance or promotional/referral credit.
- Cash bookings get no benefit of any kind. Reject server-side.
- A booking that used any benefit must be paid online at booking time
  and can never switch to cash afterwards (web, API, provider app,
  admin).
- Promotional/referral credit is never withdrawable and refunds back
  only as credit. A customer's own top-up money keeps its existing
  refund behaviour.
- Extra work or parts added later are outside the benefit and can be
  paid by any method.
- Applies to every module, current and future (services, parcel, hotel,
  food, marketplace). Any new feature that offers a benefit must
  enforce this rule and include tests proving cash is rejected.
- Super Admin cannot override this per booking. Any exception needs the
  owner's explicit approval and a code change.

### MANUAL MONEY ACTIONS — APPROVAL MODEL
Any action where a person moves, refunds, waives or adjusts money
(mismatch refunds, cancellation charge waivers, manual payouts, wallet
adjustments, and any future one) must use:
1. A dedicated permission, assignable to roles by Super Admin. Super
   Admin always holds it. Checked server-side.
2. Scope: franchise-scoped holders act only within their franchise; HQ
   holders act on all.
3. Amount limits per level as settings (franchise, HQ, above HQ = Super
   Admin only). Null limit = that level cannot approve.
4. Maker-checker above a configurable threshold: requester and approver
   must be different users.
5. A queue showing age, amount, franchise and status, with time-based
   escalation to the next level (configurable hours).
6. Reason required, full audit log, idempotent, no automatic execution
   unless the owner explicitly approves an automated rule.
7. Customer-facing money always moves through the HQ gateway or ledger;
   franchise users approve, they never hold or move funds.
Never build a manual money action as "Super Admin only" without this
model.

### THUMB RULE — VISIT/INSPECTION CHARGE ONLY WHEN NO WORK IS DONE
- The visit/inspection charge (`cancellation.visit_fee_value`, currently ₹149 launch,
  regular ₹199) applies ONLY when the provider verifiably arrived and NO work was
  performed: the customer refuses, postpones, takes an estimate only, says they must ask
  the owner, cannot be reached, or rejects the quote.
- If any service is performed, the visit charge is NEVER added. Example: ₹500 service
  completed → customer pays ₹500.
- The Prime "Free Service Visit" waiver only waives that no-work visit charge. On a
  completed job it is not applied and not consumed.
- Applies to every module and every channel (web, API, admin, bundles, invoices).

### MID-WORK CANCELLATION — ONE DECLARED AMOUNT, ONE CAP
- Work started, then stopped (spares unavailable or any other reason): the provider enters ONE amount for
  the work done (labour and parts together). The customer pays that amount. The visit charge is never added.
- It can never exceed `cancellation.interim_cap_percent` % of the booking total (price_quoted + approved
  extras), saved on the booking at creation. Null = not configured: the provider cannot submit an amount
  (fail closed). There is no minimum / floor of any kind.
- After payment the customer can raise a dispute from the order page (reason required). An admin queue
  resolves it by hand; any refund goes through the manual money approval model only (never automatic).

### THUMB RULE — EVERY PUBLIC LINK IS ADMIN-CONTROLLED, CITY-AWARE AND NEVER BREAKS
- Every public catalog page (city, category, subcategory, service, and every future
  module: parcel, hotel, food, marketplace, products, vendors) has a clean, readable
  slug. Never random suffixes; -2, -3 only on collision.
- Public URLs are city-prefixed: /{city}/{slug}. Item slugs are global and
  HQ-controlled; the city prefix scopes price, availability and providers to that city.
- Super Admin (or a role with the permission) edits slugs from the item's admin screen.
  Validated, unique, audit-logged.
- Franchise admins may edit their own city's page content (title, meta description,
  intro text), never slugs.
- Any slug change and any old URL format permanently 301-redirects to the current URL
  with the query string kept (UTM survives).
- Public URLs are never removed without a redirect.
- Business thumb rules (online-only benefits, visit charge only when no work is done,
  manual money approval model) are NOT admin switchable. Their values are
  admin-controlled; the rules are not.
