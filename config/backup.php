<?php

// REF 1CF-BACKUP-RESTORE-001 — Backup & Restore screen (System menu).
// Off-site copies are made by scripts/backup-offsite.sh (docs/DISASTER_RECOVERY.md); this screen reads them.

return [

    // rclone remote holding the backups, same value the backup script reads from .env.
    'remote' => env('BACKUP_RCLONE_REMOTE'),

    'rclone_binary' => env('BACKUP_RCLONE_BIN', 'rclone'),

    // PHP and the queue worker may run without $HOME, so rclone gets its config file explicitly.
    'rclone_config' => env('BACKUP_RCLONE_CONFIG'),

    'mysql_binary' => env('BACKUP_MYSQL_BIN', 'mysql'),

    'mysqldump_binary' => env('BACKUP_MYSQLDUMP_BIN', 'mysqldump'),

    // Local dumps (nightly backup-database.sh + the hourly staging copies).
    'local_dir' => storage_path('backups'),

    // Where a chosen backup is downloaded to and where safety dumps are kept. Not under storage/app,
    // which the daily off-site sync uploads.
    'work_dir' => storage_path('backups/restore-work'),

    'script' => base_path('scripts/backup-offsite.sh'),

    // Empty database the chosen backup is loaded into for comparing. The live database is never loaded
    // over. Created once on the server (DISASTER_RECOVERY.md); same DB user as the app.
    'restore_database' => env('RESTORE_DB_DATABASE'),

    // The newest copy older than this is shown as "Late" instead of "Healthy".
    'healthy_minutes' => (int) env('BACKUP_HEALTHY_MINUTES', 90),

];
