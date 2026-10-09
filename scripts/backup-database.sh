#!/bin/bash
#
# Nightly database backup for the 1CallFix Services database.
# Keeps the last 14 daily backups locally, deletes older ones automatically
# so this never silently fills up the disk.
#
# IMPORTANT: this backs up locally on the same VPS. That protects against
# accidental data corruption / bad migrations, but NOT against the whole
# server dying or being compromised. Step 2 (see instructions) copies these
# off-server — don't skip that part.

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Credentials come from the app's own .env (never stored in this script or in git).
# Override with DB_NAME / DB_USER / DB_PASS in the environment if needed.
env_value() { grep -E "^$1=" "$APP_DIR/.env" | head -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }
DB_NAME="${DB_NAME:-$(env_value DB_DATABASE)}"
DB_USER="${DB_USER:-$(env_value DB_USERNAME)}"
DB_PASS="${DB_PASS:-$(env_value DB_PASSWORD)}"
if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
  echo "DB_DATABASE / DB_USERNAME not found in $APP_DIR/.env" >&2
  exit 1
fi
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/storage/backups"
TIMESTAMP=$(date +%Y-%m-%d_%H-%M-%S)
BACKUP_FILE="${BACKUP_DIR}/1cal_api_${TIMESTAMP}.sql.gz"
RETENTION_DAYS=14

mkdir -p "$BACKUP_DIR"

MYSQL_PWD="$DB_PASS" mysqldump --single-transaction --quick --lock-tables=false \
  -u "$DB_USER" "$DB_NAME" | gzip > "$BACKUP_FILE"

echo "Backup created: $BACKUP_FILE"

# Delete local backups older than retention period
find "$BACKUP_DIR" -name "1cal_api_*.sql.gz" -mtime +$RETENTION_DAYS -delete

echo "Old backups cleaned (older than ${RETENTION_DAYS} days removed)"
