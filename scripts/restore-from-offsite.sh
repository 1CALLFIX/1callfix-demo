#!/bin/bash
#
# One-command restore of the 1CallFix super app from the off-site backups made by backup-offsite.sh.
# Run as callf1207 from anywhere, after the server itself is back (Hostinger snapshot or new server
# with .env + rclone in place, see docs/DISASTER_RECOVERY.md):
#
#   bash scripts/restore-from-offsite.sh                 newest dump made BEFORE this server booted
#   bash scripts/restore-from-offsite.sh --file NAME     a specific dump from db/hourly (or db/daily)
#   add --db-only                                        database only: code, files and build untouched
#                                                        (server still alive, rolling back bad data)
#
# Why "before this server booted": a restored snapshot boots with week-old data, and its own hourly cron
# may already have uploaded dumps of that old data. Anything uploaded after boot is ignored, so the
# restore always picks the last dump taken on the server that failed.
#
# Steps: maintenance mode -> safety dump -> code to origin/main (+ composer if needed) -> DB import
# -> migrate -> uploaded files -> front-end build -> caches -> queue restart -> live -> health check.
# Any failure stops the script and leaves the site in maintenance mode.

set -Eeuo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"
env_value() { grep -E "^$1=" "$APP_DIR/.env" | head -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }

STEP="start"
step() { STEP="$1"; echo; echo "=== [$1] $2"; }
trap 'echo; echo "RESTORE STOPPED at step [$STEP] (line $LINENO). If step [down] ran, the site is in maintenance mode. Send this output." >&2' ERR

usage() { echo "usage: $0 [--file NAME] [--db-only]" >&2; exit 2; }
PICK_FILE=""
DB_ONLY=0
while [ $# -gt 0 ]; do
  case "$1" in
    --file) PICK_FILE="${2:-}"; [ -n "$PICK_FILE" ] || usage; shift 2 ;;
    --db-only) DB_ONLY=1; shift ;;
    *) usage ;;
  esac
done
# Matches a whole line without grep -q, which closes the pipe early and trips pipefail.
has_line() { local LIST; LIST="$1"; grep -Fx -- "$2" <<< "$LIST" > /dev/null; }

[ -f .env ] || { echo ".env missing in $APP_DIR (DISASTER_RECOVERY.md D2 step 4)" >&2; exit 1; }
REMOTE="$(env_value BACKUP_RCLONE_REMOTE)"
DB_NAME="$(env_value DB_DATABASE)"
DB_USER="$(env_value DB_USERNAME)"
DB_PASS="$(env_value DB_PASSWORD)"
APP_URL="$(env_value APP_URL)"
[ -n "$REMOTE" ] && [ -n "$DB_NAME" ] && [ -n "$DB_USER" ] || { echo "BACKUP_RCLONE_REMOTE / DB_* missing in .env" >&2; exit 1; }
RCLONE_OPTS=(--retries 3 --low-level-retries 10 -q)

# Hold the backup locks so no cron backup dumps or syncs a half-restored state.
exec 8>"/tmp/1callfix-offsite-hourly.lock"; flock -w 600 8
exec 9>"/tmp/1callfix-offsite-daily.lock"; flock -w 1800 9

BOOT_TS="$(date -d "$(uptime -s)" +%Y-%m-%d_%H-%M-%S)"

# ---------------------------------------------------------------- choose the dump
step choose "Choosing the database dump (server booted $BOOT_TS)"
rclone lsf "$REMOTE" "${RCLONE_OPTS[@]}" > /dev/null
if [ -n "$PICK_FILE" ]; then
  if has_line "$(rclone lsf "$REMOTE/db/hourly" "${RCLONE_OPTS[@]}")" "$PICK_FILE"; then
    DUMP_DIR="db/hourly"
  else
    DUMP_DIR="db/daily"
  fi
  DUMP="$PICK_FILE"
else
  # File names carry the dump time (YYYY-MM-DD_HH-MM-SS), so a plain sort is chronological.
  before_boot() { sed -E 's/^1cal_api_(hourly_)?//; s/\.sql\.gz$//' | awk -v b="$BOOT_TS" '$0 < b'; }
  DUMP_DIR="db/hourly"
  TS=$(rclone lsf "$REMOTE/db/hourly" "${RCLONE_OPTS[@]}" | before_boot | sort | tail -n1)
  DUMP="1cal_api_hourly_${TS}.sql.gz"
  if [ -z "$TS" ]; then
    DUMP_DIR="db/daily"
    TS=$(rclone lsf "$REMOTE/db/daily" "${RCLONE_OPTS[@]}" | before_boot | sort | tail -n1)
    DUMP="1cal_api_${TS}.sql.gz"
  fi
  [ -n "$TS" ] || { echo "No dump older than the boot time found on $REMOTE" >&2; false; }
fi
echo "Dump: $DUMP_DIR/$DUMP"
echo "Everything after this time is NOT in the restore and must be checked in Razorpay afterwards."
read -r -p "Type RESTORE to replace the live database with this dump: " ANSWER
[ "$ANSWER" = "RESTORE" ] || { echo "Cancelled, nothing changed."; exit 0; }

# ---------------------------------------------------------------- maintenance + safety dump
step down "Maintenance mode"
php artisan down || true

step safety "Safety dump of the current database"
mkdir -p ~/backups ~/restore
SAFETY=~/backups/before-restore-$(date +%Y%m%d-%H%M%S).sql.gz
if MYSQL_PWD="$DB_PASS" mysqldump -u "$DB_USER" --single-transaction --routines --triggers "$DB_NAME" | gzip > "$SAFETY"; then
  ls -lh "$SAFETY"
else
  echo "WARNING: safety dump failed (empty or new database?). Continuing; nothing to keep." >&2
fi

# ---------------------------------------------------------------- code
if [ "$DB_ONLY" = 0 ]; then
step code "Code to origin/main"
OLD=$(git rev-parse --short HEAD)
git fetch origin
git merge --ff-only origin/main
NEW=$(git rev-parse --short HEAD)
echo "HEAD $OLD -> $NEW"
if ! git diff --quiet "$OLD" HEAD -- composer.lock composer.json; then
  echo "composer.lock changed: installing"
  composer install --no-dev --optimize-autoloader --no-interaction
else
  echo "composer.lock unchanged"
fi
fi

# ---------------------------------------------------------------- database
step database "Download, verify and import $DUMP"
rclone copy "$REMOTE/$DUMP_DIR/$DUMP" ~/restore/ "${RCLONE_OPTS[@]}"
gzip -t ~/restore/"$DUMP"
zcat ~/restore/"$DUMP" | tail -n 1 | grep -- "-- Dump completed"
# Empty first, so tables created after the dump cannot survive and clash with migrate.
# If the import fails after this, the safety dump above is the way back.
php artisan db:wipe --force
zcat ~/restore/"$DUMP" | MYSQL_PWD="$DB_PASS" mysql -u "$DB_USER" "$DB_NAME"
echo "Imported."

step migrate "Migrations (only those newer than the dump)"
php artisan migrate:status | grep -i pending || echo "(none pending)"
php artisan migrate --force

# ---------------------------------------------------------------- files + build
# current/ is the last good sync. If the restored server already ran its own daily sync, the newer
# files it pushed aside sit in archive folders created after boot; copy those back on top.
restore_tree() {
  local SRC="$1" DEST="$2"
  has_line "$(rclone lsf "$REMOTE/$SRC" "${RCLONE_OPTS[@]}")" "current/" || { echo "no $SRC/current on remote, skipped"; return 0; }
  rclone copy "$REMOTE/$SRC/current" "$DEST" "${RCLONE_OPTS[@]}"
  for D in $(rclone lsf "$REMOTE/$SRC/archive" --dirs-only "${RCLONE_OPTS[@]}" 2>/dev/null | tr -d / | sort); do
    if [[ "$D" > "$BOOT_TS" ]]; then
      echo "also restoring $SRC/archive/$D (pushed aside after boot)"
      rclone copy "$REMOTE/$SRC/archive/$D" "$DEST" "${RCLONE_OPTS[@]}"
    fi
  done
  echo "$SRC -> $DEST done"
}

if [ "$DB_ONLY" = 0 ]; then
step files "Uploaded files (storage/app)"
restore_tree files "$APP_DIR/storage/app"
[ -e public/storage ] || php artisan storage:link

step build "Front-end build (public/build)"
restore_tree build "$APP_DIR/public/build"
grep -o 'app-[A-Za-z0-9_-]*\.css' public/build/manifest.json | sort -u
fi

# ---------------------------------------------------------------- caches, worker, live
step caches "Four-phase cache clear"
php artisan config:clear && php artisan route:clear && php artisan view:clear && php artisan cache:clear && php artisan event:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache && php artisan event:cache

step worker "Queue worker restart (supervisor relaunches it)"
php artisan queue:restart

step up "Site live"
php artisan up

step health "Health check"
CODE=$(curl -s -o /dev/null -w '%{http_code}' -m 20 "${APP_URL%/}/up" || true)
echo "${APP_URL%/}/up -> $CODE"
[ "$CODE" = "200" ] || echo "WARNING: /up did not return 200. Check storage/logs/laravel.log." >&2
php artisan schedule:list > /tmp/1callfix-schedule.txt && head -n 5 /tmp/1callfix-schedule.txt && rm -f /tmp/1callfix-schedule.txt
git status --short | grep -v '^?? storage/' || true
git log -1 --oneline

trap - ERR
echo
echo "RESTORE DONE from $DUMP_DIR/$DUMP. Data after that time: check Razorpay payments from then until now"
echo "(DISASTER_RECOVERY.md section E). Safety dump of the replaced database: ${SAFETY}"
