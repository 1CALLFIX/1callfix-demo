# 1CallFix — Production Deploy Runbook

The same steps every time. Only two things change per deploy:

1. **The release note** `docs/deploys/<date>-<name>.md` (newest file in that folder): which migrations should be
   pending, whether a front-end build is needed, which scheduled commands are new, and what to set in the admin
   panel afterwards. Read it before you start.
2. **`NEW`**: the commit being deployed = the short hash `git log -1 --oneline` shows on `main` in step 1 (the commit
   that added the release note, or a later one if the note says so).

Rules (from CLAUDE.md): one step at a time, keep the output of each step. **If a step's ✅ check fails, STOP** and send
the output; never work around it. Never run `git clean` or `npm audit fix --force` on the server.

Paste **only the command lines** (the grey blocks), never the headings or ✅ lines: bash tries to run them as commands.

| | |
|---|---|
| Server | `callf1207@srv1422426.hstgr.cloud` |
| App folder | `/home/1callfix.com/public_html` |
| Local project | `D:\D-Downloads\1CallFix Super App\1callfix-demo` |

---

## Step 1 — Local: confirm what you are deploying (PowerShell)

```powershell
cd "D:\D-Downloads\1CallFix Super App\1callfix-demo"; git checkout main; git pull; git log -1 --oneline; git status --short -uno
```
✅ The first line of `git log` is the commit in the release note. `git status` shows nothing (no uncommitted code).
The full test suite must already have passed for this commit (the release note says so).

## Step 2 — Server: log in and set the commit once

```bash
ssh callf1207@srv1422426.hstgr.cloud
```
```bash
export NEW=PUT_THE_HASH_HERE; cd /home/1callfix.com/public_html && export OLD=$(git rev-parse --short HEAD) && echo "deploying $OLD -> $NEW"
```
✅ Prints `deploying <current> -> <new>`. `NEW` and `OLD` are used by the later steps. If you log out, or after
`su -` in step 10, run the `export NEW=...` line again (OLD is only needed for step 4).

## Step 3 — Server: back up the database

```bash
cd /home/1callfix.com/public_html && DBU=$(grep '^DB_USERNAME=' .env | cut -d= -f2- | tr -d '"') && DBN=$(grep '^DB_DATABASE=' .env | cut -d= -f2- | tr -d '"') && DBP=$(grep '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '"') && F=~/backups/pre-$NEW-$(date +%Y%m%d-%H%M%S).sql && MYSQL_PWD="$DBP" mysqldump -u "$DBU" --single-transaction --routines --triggers "$DBN" > "$F" && ls -lh "$F" && tail -1 "$F"
```
✅ A size of several MB (last time 23M, never 0) and the last line starts with `-- Dump completed`.
Write down the file name: it is the rollback point.

## Step 4 — Server: pull the code

```bash
cd /home/1callfix.com/public_html && git fetch origin && git merge --ff-only origin/main && git log -1 --oneline && echo "--- composer files changed:" && git diff --stat $OLD HEAD -- composer.lock composer.json
```
✅ `git log` starts with `$NEW`. If `merge --ff-only` refuses, STOP.
If the "composer files changed" list shows **composer.lock**, run:
```bash
cd /home/1callfix.com/public_html && composer install --no-dev --optimize-autoloader
```
(Only `composer.json` changed, or nothing listed → skip it.)

## Step 5 — Server: database migrations

```bash
cd /home/1callfix.com/public_html && php artisan migrate:status | grep -i pending; echo "(end of pending list)"
```
✅ The pending list matches the release note **exactly**. Anything extra → STOP.
If the release note says a migration widens an enum, run the SHOW COLUMNS check it gives first.
```bash
cd /home/1callfix.com/public_html && php artisan migrate --force && php artisan migrate:status | grep -i pending; echo "(end of pending list)"
```
✅ Each one says `DONE`; the pending list is now empty.
(No migrations in the release note → skip both commands.)

## Step 6 — Local: build the front-end (PowerShell)

Only when the release note says **Front-end build: yes**.
```powershell
cd "D:\D-Downloads\1CallFix Super App\1callfix-demo"; (Select-String -Path .env -Pattern '^VITE_FIREBASE_[A-Z_]+=').Line | ForEach-Object { ($_ -split '=')[0] }; npm run build
```
✅ Seven `VITE_FIREBASE_...` key names are listed (names only, never values) and the build ends with `built in`.
Then note the new CSS file name:
```powershell
(Select-String -Path public\build\manifest.json -Pattern 'app-[A-Za-z0-9_-]+\.css' -AllMatches).Matches.Value | Select-Object -Unique
```

## Step 7 — Server backup of the old build, then upload the new one

Only when step 6 ran. On the **server**:
```bash
cd /home/1callfix.com/public_html && B=~/backups/public-build-before-$NEW-$(date +%Y%m%d-%H%M%S) && cp -a public/build "$B" && du -sh "$B" && ls "$B"
```
✅ A size and `assets  fonts-manifest.json  manifest.json`.

On your **PC** (PowerShell, from the project folder; a new window is fine):
```powershell
cd "D:\D-Downloads\1CallFix Super App\1callfix-demo"; scp -r public\build\* callf1207@srv1422426.hstgr.cloud:/home/1callfix.com/public_html/public/build/
```
✅ A list of files at 100%.

## Step 8 — Server: clear and rebuild caches

```bash
cd /home/1callfix.com/public_html && grep -o 'app-[A-Za-z0-9_-]*\.css' public/build/manifest.json | sort -u
```
✅ Same CSS name as step 6 (when a build was uploaded).
```bash
cd /home/1callfix.com/public_html && php artisan config:clear && php artisan route:clear && php artisan view:clear && php artisan cache:clear && php artisan event:clear
```
```bash
cd /home/1callfix.com/public_html && php artisan config:cache
```
```bash
cd /home/1callfix.com/public_html && php artisan route:cache
```
```bash
cd /home/1callfix.com/public_html && php artisan view:cache && php artisan event:cache
```
✅ Every line says `successfully`, no red errors.

## Step 9 — Restart the queue worker (and only the worker)

```bash
exit
```
(You are now root.)
```bash
sudo supervisorctl restart onecallfix-worker:* && sudo supervisorctl status | grep onecallfix
```
✅ `RUNNING` with an uptime of a few seconds.
```bash
su - callf1207
```
```bash
export NEW=PUT_THE_HASH_HERE
```

## Step 10 — Checks

```bash
cd /home/1callfix.com/public_html && php artisan schedule:list
```
✅ Every scheduled command the release note names is in the list.
```bash
cd /home/1callfix.com/public_html && tail -n 30 storage/logs/laravel.log | cut -c1-250
```
✅ No new `ERROR` timed after you started the deploy.
```bash
cd /home/1callfix.com/public_html && git status --short && git log -1 --oneline
```
✅ `git status` shows nothing or only `storage/` entries; `git log` starts with `$NEW`.
**The deploy is not done until both are true.**

Then log in to the live admin and do the browser checks in the release note, and the admin settings it lists.

---

## Rollback (only if the release note's checks fail and the owner decides to roll back)

```bash
cd /home/1callfix.com/public_html && git log --oneline -5
```
Code back to the previous commit (`OLD` from step 2):
```bash
cd /home/1callfix.com/public_html && git checkout -B main $OLD && git log -1 --oneline
```
If migrations ran, roll back only this release's migrations (count from the release note):
```bash
cd /home/1callfix.com/public_html && php artisan migrate:rollback --step=NUMBER_FROM_RELEASE_NOTE --force
```
Front-end back:
```bash
cd /home/1callfix.com/public_html && cp -a ~/backups/public-build-before-$NEW-*/. public/build/
```
Then steps 8, 9 and 10. Restoring the database from the step-3 dump is a last resort that loses everything customers
did since the dump; do it only with the owner's go-ahead.

---

## Writing a release note (for whoever prepares the deploy)

Copy `docs/deploys/TEMPLATE.md` to `docs/deploys/<yyyy-mm-dd>-<short-name>.md`, fill it in, commit it as the last
commit of the release and push. That commit's hash is `NEW`.
