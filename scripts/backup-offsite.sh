#!/bin/bash
#
# Off-site backup for the 1CallFix super app (Google Drive via rclone).
# Companion to backup-database.sh (nightly local dump); this one copies off the VPS
# so a dead or compromised server does not take the backups with it.
#
#   backup-offsite.sh hourly   fresh DB dump -> remote db/hourly   (kept 48 hours)
#   backup-offsite.sh daily    newest nightly dump -> db/daily      (kept 30 days)
#                              on the 1st of the month also -> db/monthly (kept 400 days)
#                              storage/app -> files/current, changed/deleted files -> files/archive/<date> (kept 30 days)
#                              public/build -> build/current (same archive scheme), crontab -> server/crontab.txt
#                              .env -> env/ (ONLY when the remote is an rclone crypt remote)
#
# Restore: scripts/restore-from-offsite.sh (docs/DISASTER_RECOVERY.md).
#
# Settings, read from the app's .env (never stored in this script or in git):
#   BACKUP_RCLONE_REMOTE      required, e.g. gdrive-crypt:1callfix-superapp
#   BACKUP_PING_URL_HOURLY    optional healthchecks.io URL, pinged on success, <url>/fail on failure
#   BACKUP_PING_URL_DAILY     optional, same for the daily run

set -Eeuo pipefail

MODE="${1:-}"
if [ "$MODE" != "hourly" ] && [ "$MODE" != "daily" ]; then
  echo "usage: $0 hourly|daily" >&2
  exit 2
fi

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
env_value() { grep -E "^$1=" "$APP_DIR/.env" | head -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }

REMOTE="${BACKUP_RCLONE_REMOTE:-$(env_value BACKUP_RCLONE_REMOTE)}"
if [ "$MODE" = "hourly" ]; then
  PING_URL="${BACKUP_PING_URL_HOURLY:-$(env_value BACKUP_PING_URL_HOURLY)}"
else
  PING_URL="${BACKUP_PING_URL_DAILY:-$(env_value BACKUP_PING_URL_DAILY)}"
fi

ping_check() { [ -n "$PING_URL" ] && curl -fsS -m 10 --retry 3 -o /dev/null "$PING_URL$1" || true; }
trap 'echo "FAILED: offsite $MODE backup (line $LINENO)" >&2; ping_check /fail' ERR

if [ -z "$REMOTE" ]; then
  echo "BACKUP_RCLONE_REMOTE not set in $APP_DIR/.env" >&2
  false
fi

# One run per mode at a time; a slow upload must not pile up behind the next cron tick.
exec 9>"/tmp/1callfix-offsite-$MODE.lock"
if ! flock -n 9; then
  echo "Another $MODE run is still going; skipping." >&2
  exit 0
fi

LOCAL_DIR="$APP_DIR/storage/backups"
TIMESTAMP=$(date +%Y-%m-%d_%H-%M-%S)
RCLONE_OPTS=(--retries 3 --low-level-retries 10 --stats-one-line -q)

# A dump counts only when gzip is intact and mysqldump wrote its closing line.
verify_dump() {
  gzip -t "$1"
  zcat "$1" | tail -n 1 | grep -q -- "-- Dump completed"
}

if [ "$MODE" = "hourly" ]; then
  DB_NAME="$(env_value DB_DATABASE)"
  DB_USER="$(env_value DB_USERNAME)"
  DB_PASS="$(env_value DB_PASSWORD)"
  mkdir -p "$LOCAL_DIR/hourly"
  FILE="$LOCAL_DIR/hourly/1cal_api_hourly_${TIMESTAMP}.sql.gz"

  MYSQL_PWD="$DB_PASS" mysqldump --single-transaction --quick --lock-tables=false \
    --routines --triggers -u "$DB_USER" "$DB_NAME" | gzip > "$FILE"
  verify_dump "$FILE"

  rclone copy "$FILE" "$REMOTE/db/hourly" "${RCLONE_OPTS[@]}"
  rclone delete "$REMOTE/db/hourly" --min-age 48h "${RCLONE_OPTS[@]}"

  # Local hourly copies are only a staging area; the nightly dump stays the local history.
  find "$LOCAL_DIR/hourly" -name "1cal_api_hourly_*.sql.gz" -mmin +180 -delete

  echo "Hourly offsite backup OK: $(basename "$FILE") ($(du -h "$FILE" | cut -f1))"
else
  # Newest nightly dump written by backup-database.sh at 02:00.
  LATEST=$(ls -1t "$LOCAL_DIR"/1cal_api_*.sql.gz 2>/dev/null | grep -v hourly | head -n1 || true)
  if [ -z "$LATEST" ]; then
    echo "No nightly dump found in $LOCAL_DIR" >&2
    false
  fi
  if [ -n "$(find "$LATEST" -mmin +1440)" ]; then
    echo "Newest nightly dump is older than 24h: $LATEST" >&2
    false
  fi
  verify_dump "$LATEST"

  rclone copy "$LATEST" "$REMOTE/db/daily" "${RCLONE_OPTS[@]}"
  rclone delete "$REMOTE/db/daily" --min-age 30d "${RCLONE_OPTS[@]}"

  if [ "$(date +%d)" = "01" ]; then
    rclone copy "$LATEST" "$REMOTE/db/monthly" "${RCLONE_OPTS[@]}"
    rclone delete "$REMOTE/db/monthly" --min-age 400d "${RCLONE_OPTS[@]}"
  fi

  # Uploaded files (KYC documents, photos, banners). Anything changed or deleted since the last
  # run is moved to a dated archive folder instead of being lost.
  rclone sync "$APP_DIR/storage/app" "$REMOTE/files/current" \
    --backup-dir "$REMOTE/files/archive/$TIMESTAMP" \
    --exclude "backups/**" "${RCLONE_OPTS[@]}"
  rclone delete "$REMOTE/files/archive" --min-age 30d "${RCLONE_OPTS[@]}"
  rclone rmdirs "$REMOTE/files/archive" --leave-root "${RCLONE_OPTS[@]}"

  # Compiled front-end (git-ignored, cannot be built on the server), so a restore never waits for a local build.
  if [ -d "$APP_DIR/public/build" ]; then
    rclone sync "$APP_DIR/public/build" "$REMOTE/build/current" \
      --backup-dir "$REMOTE/build/archive/$TIMESTAMP" "${RCLONE_OPTS[@]}"
    rclone delete "$REMOTE/build/archive" --min-age 30d "${RCLONE_OPTS[@]}"
    rclone rmdirs "$REMOTE/build/archive" --leave-root "${RCLONE_OPTS[@]}"
  fi

  # The user crontab (schedule:run + backups) is the one piece of server setup not in git.
  crontab -l > "/tmp/1callfix-crontab-$TIMESTAMP.txt" 2>/dev/null || true
  rclone copyto "/tmp/1callfix-crontab-$TIMESTAMP.txt" "$REMOTE/server/crontab.txt" "${RCLONE_OPTS[@]}"
  rm -f "/tmp/1callfix-crontab-$TIMESTAMP.txt"

  # .env holds APP_KEY and every live secret: only ever upload it encrypted.
  REMOTE_NAME="${REMOTE%%:*}"
  if rclone listremotes --long | grep -E "^${REMOTE_NAME}:[[:space:]]+crypt" > /dev/null; then
    rclone copyto "$APP_DIR/.env" "$REMOTE/env/env_${TIMESTAMP}" "${RCLONE_OPTS[@]}"
    rclone delete "$REMOTE/env" --min-age 90d "${RCLONE_OPTS[@]}"
  else
    echo "WARNING: $REMOTE_NAME is not a crypt remote; .env NOT uploaded. Keep it in the password manager." >&2
  fi

  echo "Daily offsite backup OK: $(basename "$LATEST") + storage/app + public/build"
fi

ping_check ""
