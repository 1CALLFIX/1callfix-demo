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

DB_NAME="1cal_api"
DB_USER="1cal_apiadmin"
DB_PASS="Call@123#609"
BACKUP_DIR="/home/1callfix.com/public_html/api/storage/backups"
TIMESTAMP=$(date +%Y-%m-%d_%H-%M-%S)
BACKUP_FILE="${BACKUP_DIR}/1cal_api_${TIMESTAMP}.sql.gz"
RETENTION_DAYS=14

mkdir -p "$BACKUP_DIR"

mysqldump --single-transaction --quick --lock-tables=false \
  -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" | gzip > "$BACKUP_FILE"

echo "Backup created: $BACKUP_FILE"

# Delete local backups older than retention period
find "$BACKUP_DIR" -name "1cal_api_*.sql.gz" -mtime +$RETENTION_DAYS -delete

echo "Old backups cleaned (older than ${RETENTION_DAYS} days removed)"
