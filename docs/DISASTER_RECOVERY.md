# 1CallFix — Backups and Disaster Recovery

Copy-paste commands, same style as `docs/DEPLOY_RUNBOOK.md`. Paste **only the grey blocks**, never the ✅ lines.

| | |
|---|---|
| Server | `callf1207@srv1422426.hstgr.cloud` |
| App folder | `/home/1callfix.com/public_html` |
| Off-site remote | `gdrive-crypt:superapp` (Google Drive, encrypted by rclone) |

## What is backed up

| Layer | What | When | Kept | Where |
|---|---|---|---|---|
| 1 | Whole VPS snapshot (code, `.env`, cron, supervisor, vhost, DB, files) | weekly (Hostinger) | Hostinger's default | Hostinger |
| 2 | DB dump (`scripts/backup-database.sh`) | daily 02:00 | 14 days | same VPS, `storage/backups/` |
| 3 | DB dump (`scripts/backup-offsite.sh hourly`) | every hour at :15 | 48 hours | Google Drive `db/hourly` |
| 4 | Newest nightly dump (`backup-offsite.sh daily`) | daily 03:30 | 30 days, plus the 1st of each month for 400 days | Google Drive `db/daily`, `db/monthly` |
| 5 | `storage/app` (KYC docs, photos, banners) | daily 03:30 | current + 30 days of changed/deleted files | Google Drive `files/current`, `files/archive` |
| 6 | `.env` (APP_KEY + every live secret) | daily 03:30 | 90 days | Google Drive `env/` (crypt remote only) |
| 7 | Code | every push | forever | GitHub |

**Worst case data loss: 1 hour of database, 1 day of uploaded files.**
Missed backups email the owner through healthchecks.io (one-time setup, part A step 5).

**Must also be kept outside the server, in the password manager:**
the rclone crypt password + salt (without them the Drive backups cannot be decrypted, by anyone), a copy of `.env`,
the Google account that owns the Drive folder, Hostinger + CyberPanel logins, GitHub access, domain/DNS login.

---

## A. One-time setup (on the server, as `callf1207`)

### A1. rclone is installed and Google Drive is reachable
```bash
rclone version | head -1; rclone listremotes --long
```
✅ A version line, and a `drive` remote is listed (the POS one, e.g. `gdrive: drive`).

The POS (`pos.1callfix.com`, files in `/home/1callfix.com/public_html/pos/callfixpos-saas/`) runs on this same VPS,
so its Google Drive login can be reused. If nothing is listed, the POS remote was set up as **root**. Copy root's
config to `callf1207` (exit to root, as in runbook step 9):
```bash
ls -l /root/.config/rclone/rclone.conf && U=callf1207 && H=$(getent passwd $U | cut -d: -f6) && mkdir -p $H/.config/rclone && test ! -e $H/.config/rclone/rclone.conf && install -o $U -g $(id -gn $U) -m 600 /root/.config/rclone/rclone.conf $H/.config/rclone/rclone.conf && echo COPIED
```
✅ `COPIED`. (If it stops at `test ! -e`, callf1207 already has a config: send its `rclone listremotes --long`
output instead of overwriting it.) Then `su - callf1207` and run the A1 check again. The POS cron keeps working
unchanged; both configs use the same Google login.

The super app backups go to their **own folder** (`1callfix-superapp-backups`, A2), never into the POS folder.
Replace `gdrive` below with the remote name A1 printed.

### A2. Create the encrypted remote on top of Google Drive
The DB holds customer phone numbers, addresses and KYC data, so it is encrypted before it leaves the server.
```bash
rclone config
```
Answer: `n` → name `gdrive-crypt` → type `crypt` → remote `gdrive:1callfix-superapp-backups` → filename encryption
`1` (standard) → directory name encryption `1` (true) → password: `g` (generate), 1024 bits, **copy it** → salt: `g`,
**copy it** → no advanced config → `y` (keep) → `q`.

**Save both generated passwords in the password manager now, labelled "1CallFix rclone crypt".** Without them the
backups are useless.
```bash
rclone listremotes --long; rclone mkdir gdrive-crypt:superapp && rclone lsd gdrive-crypt: && echo REMOTE_OK
```
✅ `gdrive-crypt: crypt` is listed and `REMOTE_OK` prints. In Google Drive you will see a folder
`1callfix-superapp-backups` with scrambled names inside. That is correct.

### A3. Healthchecks (missed-backup alert)
At https://healthchecks.io (free) create two checks:
- `1callfix-superapp-hourly`: period **1 hour**, grace **30 minutes**
- `1callfix-superapp-daily`: period **1 day**, grace **2 hours**

Copy each check's ping URL (`https://hc-ping.com/...`). Set the email alert to the owner.

### A4. Settings in `.env`
```bash
cd /home/1callfix.com/public_html && cp -p .env ~/backups/env-before-offsite-$(date +%Y%m%d-%H%M%S) && nano .env
```
Add at the end (put in your own two ping URLs):
```
BACKUP_RCLONE_REMOTE=gdrive-crypt:superapp
BACKUP_PING_URL_HOURLY=https://hc-ping.com/PUT-HOURLY-UUID
BACKUP_PING_URL_DAILY=https://hc-ping.com/PUT-DAILY-UUID
```
No cache clear needed: the script reads `.env` directly, the app does not use these keys.

### A5. First runs by hand
The script arrives through git (normal deploy, or `git fetch origin && git merge --ff-only origin/main`).
```bash
cd /home/1callfix.com/public_html && bash scripts/backup-offsite.sh hourly && bash scripts/backup-offsite.sh daily
```
✅ `Hourly offsite backup OK: ...` and `Daily offsite backup OK: ...`. No `WARNING ... not a crypt remote`.
Both checks on healthchecks.io turn green.
```bash
rclone ls gdrive-crypt:superapp --max-depth 2
```
✅ Lists `db/hourly/1cal_api_hourly_*.sql.gz`, `db/daily/1cal_api_*.sql.gz` and `env/env_*`.

### A6. Cron
```bash
crontab -l
```
✅ Shows the existing `schedule:run` line and the 02:00 `backup-database.sh` line. Keep them.
```bash
( crontab -l; echo '15 * * * * /bin/bash /home/1callfix.com/public_html/scripts/backup-offsite.sh hourly >> /home/1callfix.com/public_html/storage/logs/backup-offsite.log 2>&1'; echo '30 3 * * * /bin/bash /home/1callfix.com/public_html/scripts/backup-offsite.sh daily >> /home/1callfix.com/public_html/storage/logs/backup-offsite.log 2>&1' ) | crontab - && crontab -l
```
✅ The two new lines appear, with everything that was there before. Next morning: both checks green, and
`tail -5 storage/logs/backup-offsite.log` shows OK lines.

If the 02:00 `backup-database.sh` line is **not** in the crontab (it may sit in CyberPanel's cron screen instead),
check there. The daily run fails loudly if no nightly dump from the last 24 hours exists.

---

## B. Restore drill (monthly, about 10 minutes, nothing on the live site changes)
```bash
mkdir -p ~/restore-test && F=$(rclone lsf gdrive-crypt:superapp/db/hourly | sort | tail -1) && rclone copy "gdrive-crypt:superapp/db/hourly/$F" ~/restore-test/ && gzip -t ~/restore-test/$F && zcat ~/restore-test/$F | tail -1 && ls -lh ~/restore-test/$F
```
✅ Ends with `-- Dump completed on <date within the last hour>` and a size near the earlier ones.
```bash
rclone ls gdrive-crypt:superapp/files/current | wc -l; ls -R /home/1callfix.com/public_html/storage/app | grep -c '\.'
```
✅ Similar counts. Then `rm -rf ~/restore-test`. Note the date of the drill in this file's log at the bottom.

---

## C. Something went wrong, server still alive (bad data, bad migration, wrong admin action)
Use the newest dump from **before** the problem. Restore into a separate database first if only a few rows are
needed; restore over the live DB only when the whole DB must go back.

```bash
cd /home/1callfix.com/public_html && php artisan down && DBU=$(grep '^DB_USERNAME=' .env | cut -d= -f2- | tr -d '"') && DBN=$(grep '^DB_DATABASE=' .env | cut -d= -f2- | tr -d '"') && DBP=$(grep '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '"') && S=~/backups/before-restore-$(date +%Y%m%d-%H%M%S).sql && MYSQL_PWD="$DBP" mysqldump -u "$DBU" --single-transaction --routines --triggers "$DBN" > "$S" && ls -lh "$S" && tail -1 "$S"
```
✅ A safety dump of the current state, ending with `-- Dump completed`. Then pick the dump to restore:
```bash
ls -lt ~/backups storage/backups | head -20; rclone lsl gdrive-crypt:superapp/db/hourly | sort -k2,3 | tail -10
```
Restore (set `F` to the chosen file; from Drive, `rclone copy gdrive-crypt:superapp/db/hourly/<name> ~/` first):
```bash
F=PUT_FILE_PATH; gzip -t "$F" && zcat "$F" | tail -1 && zcat "$F" | MYSQL_PWD="$DBP" mysql -u "$DBU" "$DBN" && php artisan migrate:status | grep -i pending; echo "(end of pending list)"
```
✅ `-- Dump completed`, no mysql error, nothing pending. Then runbook step 8 (cache clear), step 9 (worker restart),
`php artisan up`, and section E.

---

## D. VPS dead or unusable

### D1. Fastest: Hostinger snapshot + newest off-site data (about 1 hour)
1. hPanel → VPS → Snapshots & Backups → restore the newest weekly snapshot. This brings back code, `.env`, cron,
   supervisor, vhost and SSL, but the DB and files are up to 7 days old.
2. Log in, take the site down and set the DB variables:
```bash
cd /home/1callfix.com/public_html && php artisan down && DBU=$(grep '^DB_USERNAME=' .env | cut -d= -f2- | tr -d '"') && DBN=$(grep '^DB_DATABASE=' .env | cut -d= -f2- | tr -d '"') && DBP=$(grep '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '"') && rclone listremotes
```
✅ `gdrive-crypt:` is listed (the snapshot carries rclone's config). If not, redo A1 + A2 with the **saved** crypt
password and salt (entered with `y`, not `g`).
3. Bring the code to the last deployed commit (the snapshot may be older):
```bash
git fetch origin && git merge --ff-only origin/main && git log -1 --oneline
```
If `composer.lock` changed since the snapshot: `composer install --no-dev --optimize-autoloader`. If the front-end
changed: runbook steps 6–7 (local build + scp).
4. Newest DB dump from Drive:
```bash
mkdir -p ~/restore && F=$(rclone lsf gdrive-crypt:superapp/db/hourly | sort | tail -1) && echo "$F" && rclone copy "gdrive-crypt:superapp/db/hourly/$F" ~/restore/ && gzip -t ~/restore/$F && zcat ~/restore/$F | tail -1 && zcat ~/restore/$F | MYSQL_PWD="$DBP" mysql -u "$DBU" "$DBN" && php artisan migrate:status | grep -i pending; echo "(end of pending list)"
```
✅ File name shows the last hour before the failure, `-- Dump completed`, no error. If migrations are pending
(dump older than the code), run `php artisan migrate --force`.
5. Uploaded files (copy, never sync, so nothing on the server is deleted):
```bash
rclone copy gdrive-crypt:superapp/files/current storage/app && ls storage/app
```
6. Runbook step 8 (four-phase cache clear), step 9 (worker restart), then `php artisan up`, then section E.

### D2. Hostinger itself unavailable: new server from scratch (about 3–4 hours)
1. New VPS (Ubuntu), install CyberPanel, create website `1callfix.com`, PHP version matching `composer.json`,
   MySQL DB + user (new password), SSL. Install rclone, git, composer, supervisor.
2. `cd /home/1callfix.com/public_html && git clone <GitHub repo> .` and
   `composer install --no-dev --optimize-autoloader`.
3. Set up `gdrive` + `gdrive-crypt` exactly as A1–A2, entering the **saved** password and salt.
4. `.env`: `rclone lsf gdrive-crypt:superapp/env | sort | tail -1`, copy it to `.env`, then change only the
   `DB_*` lines to the new DB. **Keep APP_KEY unchanged.** (Or take `.env` from the password manager.)
5. DB and files: D1 steps 4–5.
6. `php artisan storage:link`. Upload the build (runbook steps 6–7). Supervisor worker from
   `deploy/supervisor/onecallfix-worker.conf`. Cron: `* * * * * php artisan schedule:run` + the A6 lines + the
   02:00 `backup-database.sh` line.
7. Point DNS (`1callfix.com`, `www`, `api`) to the new IP. Keep the TTL at 300 seconds in normal times so this
   takes minutes, not hours.
8. Runbook step 8, step 9, step 10 checks, then section E.

---

## E. After any restore
1. **Payments made after the dump time.** Razorpay dashboard → Payments, from the dump's timestamp to now. Razorpay
   resends failed webhooks for about 24 hours, so most will arrive on their own once the site is up. Any captured
   payment with no matching paid booking/order after that: fix through the normal admin screens and the manual money
   approval model, never by direct DB edits.
2. Bookings created in the lost window: customers may contact support. Recreate them from the Razorpay record and
   the customer's word.
3. `tail -n 50 storage/logs/laravel.log` (no new errors), `php artisan queue:failed`, `php artisan schedule:list`.
4. Browser: home page, login, one booking page, admin dashboard.
5. Run section B once on the restored server so backups are confirmed working again, and check healthchecks is green.

---

## Drill log
| Date | Who | Result |
|---|---|---|
| | | |
