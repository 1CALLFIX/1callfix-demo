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

    // The backup script names its files with the server's own clock (`date`), which is India time, while the app
    // runs in UTC. File-name times are read in this zone so "how old is the newest copy" is right.
    'clock_timezone' => env('BACKUP_CLOCK_TIMEZONE', 'Asia/Kolkata'),

    // Failure alert (backups:check-offsite, every 15 minutes): admins get an email + push when the newest hourly
    // copy on the Drive is older than this (150 = two hourly runs missed in a row, so one blip stays quiet)...
    'alert_after_minutes' => (int) env('BACKUP_ALERT_AFTER_MINUTES', 150),

    // ...or the newest daily copy is older than this (the daily run is at 03:30, copying the 02:00 dump).
    'daily_alert_after_hours' => (int) env('BACKUP_DAILY_ALERT_AFTER_HOURS', 28),

    // While it stays broken the alert repeats this often; one "working again" message follows the fix.
    'alert_repeat_hours' => (int) env('BACKUP_ALERT_REPEAT_HOURS', 6),

    // Where cron writes the backup script's output; the alert quotes the latest error from it.
    'log' => storage_path('logs/backup-offsite.log'),

];
