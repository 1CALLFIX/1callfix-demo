<?php

namespace App\Console\Commands;

use App\Services\AdminOpsAlertService;
use App\Services\Backup\BackupCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Off-site backup failure alert (2026-11-21). The Drive filled up on 29 Oct and scripts/backup-offsite.sh failed
 * every hour for three weeks without anyone knowing. Every 15 minutes this looks at the newest copies that actually
 * reached the Drive (BackupCatalog, kept fresh by backups:refresh-catalog) and emails + pushes the admins who can
 * open Backup & Restore when:
 *   - the newest hourly copy is older than backup.alert_after_minutes (default 150: two runs missed), or
 *   - the newest daily copy is older than backup.daily_alert_after_hours (default 28), or
 *   - the Drive cannot be listed at all.
 * Watching the arrived copies rather than the script's exit code also catches a removed cron line or a stuck run.
 * It repeats every backup.alert_repeat_hours while still broken and sends one "working again" message after.
 * Reads only; never runs or changes a backup.
 */
class CheckOffsiteBackup extends Command
{
    protected $signature = 'backups:check-offsite';

    protected $description = 'Alerts admins (email + push) when off-site backups stop reaching the Drive, and when they recover';

    /** {since, alerted_at} of the open problem. Cache, not a setting: losing it only means one extra alert. */
    public const STATE_KEY = 'backup-alert.state';

    public function handle(BackupCatalog $catalog, AdminOpsAlertService $alerts): int
    {
        if (! config('backup.remote')) {
            $this->line('Off-site backup is not set up here; nothing to check.');

            return self::SUCCESS;
        }

        $status = $catalog->offsiteStatus();
        $problems = $this->problems($status);
        $state = Cache::get(self::STATE_KEY);

        if ($problems === []) {
            if ($state) {
                $sent = $alerts->backupAlert('recovered', [
                    'Off-site backup is working again: the newest copy reached Google Drive at '.$this->show($status['hourly'] ?? $status['daily']).'.',
                    'It was failing since '.$this->show(CarbonImmutable::parse($state['since'])).'.',
                ]);
                Cache::forget(self::STATE_KEY);
                $this->info("Backups are arriving again; recovery message sent to {$sent} admin(s).");
            } else {
                $this->info('Off-site backups are arriving on time.');
            }

            return self::SUCCESS;
        }

        $repeatHours = max(1, (int) config('backup.alert_repeat_hours', 6));
        if ($state && CarbonImmutable::parse($state['alerted_at'])->greaterThan(now()->subHours($repeatHours))) {
            $this->warn('Still failing; admins were told at '.$this->show(CarbonImmutable::parse($state['alerted_at'])).'.');

            return self::SUCCESS;
        }

        $sent = $alerts->backupAlert('failing', $problems, $this->reason($status['remote_error']));
        Cache::forever(self::STATE_KEY, [
            'since' => $state['since'] ?? now()->toIso8601String(),
            'alerted_at' => now()->toIso8601String(),
        ]);
        $this->warn(implode(' ', $problems)." Alert sent to {$sent} admin(s).");

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function problems(array $status): array
    {
        if ($status['remote_error']) {
            return ['The server cannot read the backup folder on Google Drive, so it cannot tell whether backups arrive.'];
        }

        $problems = [];
        $hourlyLimit = now()->subMinutes(max(30, (int) config('backup.alert_after_minutes', 150)));
        $dailyLimit = now()->subHours(max(2, (int) config('backup.daily_alert_after_hours', 28)));

        if (! $status['hourly'] || $status['hourly']->lessThan($hourlyLimit)) {
            $problems[] = $status['hourly']
                ? 'No new hourly database backup has reached Google Drive since '.$this->show($status['hourly']).'.'
                : 'No hourly database backup found on Google Drive.';
        }

        if (! $status['daily'] || $status['daily']->lessThan($dailyLimit)) {
            $problems[] = $status['daily']
                ? 'No new daily backup (database, photos and documents) since '.$this->show($status['daily']).'.'
                : 'No daily backup found on Google Drive.';
        }

        return $problems;
    }

    /** The cause in plain words: the latest error the script wrote after its last success, or the listing error. */
    private function reason(?string $remoteError): ?string
    {
        $text = $remoteError ?? $this->latestLogError();

        if ($text === null) {
            return 'The backup log shows no error, so the scheduled backup may not be running at all: check the server cron (crontab -l).';
        }

        if (Str::contains($text, ['storageQuotaExceeded', 'storage quota'])) {
            return 'Cause: the Google Drive is full. Free up space on that Google account (Drive, Gmail and Photos share it); the next hourly run then works on its own.';
        }

        if (Str::contains($text, ['invalid_grant', 'token expired', 'oauth2', 'couldn\'t fetch token'])) {
            return 'Cause: the Google Drive sign-in on the server has expired. Reconnect it (rclone config reconnect), see docs/DISASTER_RECOVERY.md.';
        }

        return 'Latest error: '.Str::limit(trim($text), 220);
    }

    private function latestLogError(): ?string
    {
        $path = (string) config('backup.log');
        if ($path === '' || ! is_readable($path)) {
            return null;
        }

        $handle = fopen($path, 'rb');
        $size = filesize($path) ?: 0;
        fseek($handle, max(0, $size - 65536));
        $lines = preg_split('/\R/', (string) stream_get_contents($handle)) ?: [];
        fclose($handle);

        $error = null;
        foreach ($lines as $line) {
            if (str_contains($line, 'backup OK:')) {
                $error = null; // fixed since; only errors after the last success count
            } elseif (preg_match('/ERROR|FAILED|No nightly dump|older than 24h|not set|denied/i', $line)) {
                // rclone's own line carries the cause; the script's "FAILED: ... (line N)" only says that it failed.
                if ($error === null || ! str_starts_with(trim($line), 'FAILED:')) {
                    $error = $line;
                }
            }
        }

        return $error;
    }

    private function show(?CarbonImmutable $at): string
    {
        return $at ? $at->setTimezone(BackupCatalog::clockZone())->format('d/m/Y, h:i A') : 'unknown';
    }
}
