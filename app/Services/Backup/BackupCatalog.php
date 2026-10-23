<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * REF 1CF-BACKUP-RESTORE-001 — the database backups a restore can start from: the off-site copies
 * (db/hourly, db/daily, db/monthly) plus the local nightly dumps, newest first, and whether backups
 * are currently arriving on time.
 *
 * A backup is identified as "<folder>/<file name>"; the time comes from the file name
 * (…_YYYY-MM-DD_HH-MM-SS.sql.gz, server clock), the same as scripts/backup-offsite.sh writes it.
 */
class BackupCatalog
{
    public const FOLDERS = ['hourly', 'daily', 'monthly'];

    /** Listing the encrypted Drive takes seconds, so the screen reads this cache; backups:refresh-catalog keeps it warm. */
    public const CACHE_KEY = 'backup-catalog.remote';

    private ?array $memo = null;

    public function __construct(private BackupShell $shell) {}

    /**
     * @return array{backups: list<array{id: string, folder: string, file: string, at: CarbonImmutable, label: string}>, remote_error: ?string}
     */
    public function all(): array
    {
        return $this->memo ??= $this->build();
    }

    /** True when the off-site list is already cached (or not set up), so all() answers without calling rclone. */
    public function isReady(): bool
    {
        return ! config('backup.remote') || Cache::has(self::CACHE_KEY);
    }

    /** List the off-site folder again now (scheduler, after Back up now). */
    public function refresh(): void
    {
        $this->forgetCache();
        $this->all();
    }

    private function build(): array
    {
        $remoteError = null;
        $byId = [];

        try {
            foreach ($this->remoteFiles() as $folder => $files) {
                foreach ($files as $file) {
                    if ($entry = $this->entry($folder, $file)) {
                        $byId[$entry['id']] = $entry;
                    }
                }
            }
        } catch (Throwable $e) {
            $remoteError = $e->getMessage();
        }

        // Local nightly dumps (14 days on the server). Listed too, so the screen still works when
        // the off-site copy is unreachable.
        foreach (File::glob(config('backup.local_dir').'/1cal_api_*.sql.gz') ?: [] as $path) {
            $file = basename($path);
            if (! isset($byId['daily/'.$file]) && ($entry = $this->entry('local', $file))) {
                $byId[$entry['id']] = $entry;
            }
        }

        $backups = array_values($byId);
        usort($backups, fn ($a, $b) => $b['at'] <=> $a['at']);

        return ['backups' => $backups, 'remote_error' => $remoteError];
    }

    /** @return array{last_at: ?CarbonImmutable, healthy: bool, copies: int, remote_error: ?string} */
    public function status(): array
    {
        $all = $this->all();
        $last = $all['backups'][0]['at'] ?? null;

        return [
            'last_at' => $last,
            'healthy' => $last !== null && $all['remote_error'] === null
                && $last->greaterThan(CarbonImmutable::now()->subMinutes(config('backup.healthy_minutes'))),
            'copies' => count($all['backups']),
            'remote_error' => $all['remote_error'],
        ];
    }

    /** @return array{id: string, folder: string, file: string, at: CarbonImmutable, label: string} */
    public function find(string $id): array
    {
        foreach ($this->all()['backups'] as $backup) {
            if ($backup['id'] === $id) {
                return $backup;
            }
        }

        throw new RuntimeException('That backup is no longer available. Choose another one.');
    }

    /** Local path of the backup: the local copy when the server still has it, otherwise downloaded. */
    public function fetch(array $backup): string
    {
        foreach ([config('backup.local_dir').'/'.$backup['file'], config('backup.local_dir').'/hourly/'.$backup['file']] as $local) {
            if (File::exists($local)) {
                return $local;
            }
        }

        if ($backup['folder'] === 'local') {
            throw new RuntimeException('The local backup '.$backup['file'].' is gone.');
        }

        return $this->shell->remoteDownload('db/'.$backup['folder'], $backup['file'], config('backup.work_dir'));
    }

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->memo = null;
    }

    /** @return array<string, list<string>> */
    private function remoteFiles(): array
    {
        if (! config('backup.remote')) {
            throw new RuntimeException('Off-site backup is not set up on this server (BACKUP_RCLONE_REMOTE).');
        }

        $cached = Cache::get(self::CACHE_KEY);
        if ($cached === null) {
            // A failure is cached too (briefly), so an unreachable Drive does not make every page load wait.
            try {
                $tree = $this->shell->remoteTree('db');
                $cached = ['files' => array_map(fn ($folder) => $tree[$folder] ?? [], array_combine(self::FOLDERS, self::FOLDERS)), 'error' => null];
                Cache::put(self::CACHE_KEY, $cached, now()->addMinutes(15));
            } catch (Throwable $e) {
                $cached = ['files' => [], 'error' => $e->getMessage()];
                Cache::put(self::CACHE_KEY, $cached, now()->addMinute());
            }
        }

        if ($cached['error']) {
            throw new RuntimeException($cached['error']);
        }

        return $cached['files'];
    }

    private function entry(string $folder, string $file): ?array
    {
        if (! preg_match('/_(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})\.sql\.gz$/', $file, $m)) {
            return null;
        }

        $at = CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$m[1]} {$m[2]}:{$m[3]}:{$m[4]}");
        $kind = ['hourly' => 'hourly', 'daily' => 'daily', 'monthly' => 'monthly', 'local' => 'on this server'][$folder];

        return [
            'id' => $folder.'/'.$file,
            'folder' => $folder,
            'file' => $file,
            'at' => $at,
            'label' => $at->format('d/m/Y H:i').' ('.$kind.')',
        ];
    }
}
