# 1CallFix — Rollback Plan

**Written:** Production Hardening session, 2026-08-20. Companion to
`docs/DEPLOYMENT_RUNBOOK.md` — read that first for the deploy sequence this
plan reverses. Same target environment: Hostinger VPS, CyberPanel +
OpenLiteSpeed, `/home/1callfix.com/public_html/api/`, deploy user
`callf1207`. No separate staging environment exists — a rollback here is a
rollback of the one real production system.

---

## 1. Decide: roll back, or hotfix forward?

Not every bad deploy needs a full rollback. Use this to decide quickly
under pressure, rather than defaulting to the more disruptive option out
of panic:

| Situation | Action |
|---|---|
| A migration is mid-run or just failed | **Roll back.** Do not try to "finish" a broken migration live — see §3. |
| `APP_DEBUG` is leaking stack traces (the exact `KNOWN_RISKS_AND_DECISIONS.md` item 25 scenario) | **Hotfix forward, immediately** — this is a one-line `.env` change on the server, faster than any code rollback, and the fix itself carries zero risk. Fix it directly via SSH, confirm with the same live debug-exposure test the runbook's §3 step 6 describes, then decide separately whether the deploy that shipped it also needs a full rollback. |
| A new feature has a real bug but the rest of the deploy is fine and nothing is actively broken for users | **Hotfix forward** — write a fix, run the full suite, deploy the fix through the normal runbook sequence. Rolling back would also revert unrelated, correct changes in the same deploy. |
| The health check (`/up`) is returning 500, or the admin panel / booking flow is actually down for real users | **Roll back.** Every minute spent debugging live is a minute of real downtime; reverting to the last known-good state first, then debugging calmly off to the side, is faster and safer. |
| Data corruption is suspected (wrong amounts, bookings in an impossible state, etc.) | **Roll back the code immediately, then assess the database separately** (§4) before deciding whether a DB restore is also needed — don't let more requests hit the broken code path while deciding. |

**Default when unsure: roll back first, investigate second.** Given this
system currently carries the pre-launch data volume noted in the runbook's
header (0 bookings, 2 users, as of the last confirmed check) — but that
will not stay true, so this plan is written for the "real users, real
money" case it needs to work for, not the low-stakes case it happens to be
in today.

---

## 2. Rolling back the code

```bash
ssh callf1207@srv1422426.hstgr.cloud
cd /home/1callfix.com/public_html/api/

# 1. Maintenance mode first -- a half-rolled-back codebase serving live
#    traffic is worse than a maintenance page.
php artisan down --secret="$(openssl rand -hex 16)"

# 2. Identify the last known-good commit -- this should already be
#    written down from the pre-deploy checklist (DEPLOYMENT_RUNBOOK.md §1
#    step 2: "confirm the target branch... git log -1 --oneline" -- that
#    output, taken BEFORE the bad deploy, is exactly what you revert to).
git log --oneline -5

# 3. Revert to it. Prefer `git reset --hard <known-good-sha>` only if
#    nothing else has been committed/deployed on top since (true for this
#    single-environment, direct-to-production setup) -- confirm
#    `git status` is clean and this IS the production checkout, not a
#    local machine, before running --hard.
git reset --hard <known-good-sha>

# 4. Reinstall dependencies for THAT commit's composer.lock/package-lock
#    (a rollback that skips this can leave newer vendor/ code paired with
#    older application code, or vice versa -- a real source of confusing
#    bugs that look like the rollback "didn't work").
composer install --no-dev --optimize-autoloader
npm install && npm run build

# 5. Re-cache against the reverted code (a stale cache from the BAD
#    deploy is exactly the kind of "rollback didn't take effect" trap
#    DEPLOYMENT_RUNBOOK.md §5 warns about for forward deploys -- it
#    applies symmetrically here).
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 6. Restart the queue worker -- it must load the reverted code, not keep
#    running whatever the bad deploy left in memory.
sudo supervisorctl restart onecallfix-worker:*

# 7. Verify (same checks as DEPLOYMENT_RUNBOOK.md §3) BEFORE reopening.
curl -s -o /dev/null -w "%{http_code}\n" https://api.1callfix.com/up

# 8. Reopen once verified.
php artisan up
```

---

## 3. Reverting a migration

**Decide this BEFORE running `migrate --force` in the first place** —
`DEPLOYMENT_RUNBOOK.md`'s own migration-reversibility audit (Production
Hardening session, Part 2) confirmed every migration from at least the
last three build sessions has a real, correct `down()` — not a rubber
stamp. That means `php artisan migrate:rollback` is a genuinely safe,
tested operation for this codebase's recent history, not a leap of faith.

```bash
# Roll back the most recent migration "batch" (everything that ran
# together in the last `migrate` call -- exactly what a single bad deploy
# would have added).
php artisan migrate:rollback --force

# If you need to roll back a SPECIFIC number of batches instead (e.g. the
# bad deploy's migrations were batched separately from an earlier,
# still-wanted migration):
php artisan migrate:rollback --step=1 --force
```

**Two situations where `migrate:rollback` is NOT enough by itself:**

1. **A migration that seeds/renames data** (e.g. the `rename_*_module_to_*`
   pattern used repeatedly in this codebase's own migration history) —
   its `down()` reverses the specific rows it touched, which is correct
   IF no other write happened to those same rows since. If real user
   activity happened between the bad migration and the rollback (e.g. a
   franchise's module got re-toggled by an admin in the meantime),
   `migrate:rollback` will still run cleanly but may not represent the
   actual desired end state — check the affected table's data manually
   after rolling back, don't just trust a clean rollback == correct data.

2. **A migration already ran against real, populated tables with no safe
   `down()` path for data added by the APPLICATION after the migration
   ran** (e.g. a new column that real bookings have since written
   non-null values into, and the `down()` simply drops the column,
   destroying that data). None of the migrations audited this session hit
   this case, but if a FUTURE migration ever does, the correct order is:
   **restore from the pre-deploy backup (§4) instead of rolling the
   migration back naively** — don't let `down()`'s mechanical correctness
   convince you it also preserves real data if the schema and the data
   have diverged since it ran.

**When in doubt about which case applies:** treat it as case 2. A restore
from backup is slower but never silently wrong; a rollback that "succeeds"
but drops real data is a worse outcome than the downtime a restore costs.

---

## 4. Restoring from backup

Per `DEPLOYMENT_RUNBOOK.md` §0/§1: **backup automation status for this
application is unconfirmed**, not merely undocumented
(`KNOWN_RISKS_AND_DECISIONS.md` item 21). This section assumes the manual
backup `DEPLOYMENT_RUNBOOK.md` §1 step 1 requires before every deploy that
includes a migration — if that step was skipped, there may be nothing
current to restore from, only whatever the last confirmed nightly
`mysqldump`/Hostinger snapshot turns out to actually be (verify this is
real, on the real server, before relying on it — do not assume
`PROJECT_HANDOFF.md`'s description of it is still accurate).

```bash
# 1. Confirm what backups actually exist before doing anything destructive.
ls -lh ~/backups/

# 2. Take a CURRENT backup of the (broken) state first, even though it's
#    broken -- if the restore itself goes wrong, or if it turns out only
#    PART of the data was actually bad, you want the pre-restore state
#    recoverable too.
mysqldump -u <db_user> -p 1cal_api > ~/backups/1cal_api_pre-restore_$(date +%Y%m%d_%H%M%S).sql

# 3. Restore the known-good dump (adjust the filename to the real one
#    identified in step 1 -- taken BEFORE the bad deploy per
#    DEPLOYMENT_RUNBOOK.md §1 step 1).
mysql -u <db_user> -p 1cal_api < ~/backups/1cal_api_<known-good-timestamp>.sql

# 4. Roll the CODE back too (§2) to match the schema/data the restored
#    dump expects -- a restored old database paired with new application
#    code expecting a newer schema is its own source of real breakage.

# 5. Re-run the full post-deploy verification (DEPLOYMENT_RUNBOOK.md §3)
#    before reopening to the public.
```

**If a Hostinger VPS-level snapshot restore is used instead of a
`mysqldump` restore** (per `PROJECT_HANDOFF.md`'s "weekly Hostinger VPS
snapshot, Thursdays" — if confirmed still real and running): that restores
the ENTIRE server state (code + database + everything else on disk) to
the snapshot's point in time, which is a much bigger, slower, more
disruptive action than a targeted `mysqldump` restore. Reserve it for a
scenario where the server itself is compromised/corrupted beyond a
database-level fix, not as the default path for "the last deploy broke
something."

---

## 5. After the rollback — don't skip this

1. **Write down exactly what broke and why**, in `KNOWN_RISKS_AND_DECISIONS.md`
   if it reveals a real, recurring gap (this repo's own established
   convention — see items 25, 45-54 for the pattern), or as a normal
   commit-message postmortem if it was a one-off mistake.
2. **Add a regression test** for whatever broke, before re-attempting the
   deploy — this repo's own working discipline (every session's "full
   test suite green before every commit, regression tests for every fix")
   applies exactly as much to a rollback's root cause as to any other bug.
3. **Re-run the FULL pre-deploy checklist** (`DEPLOYMENT_RUNBOOK.md` §1)
   before trying again — do not re-deploy the same broken commit assuming
   "it'll work this time."
