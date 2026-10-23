<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * REF 1CF-BACKUP-RESTORE-001 — every call to the outside world the Backup & Restore screen makes:
 * rclone (list / download), mysqldump (safety copy) and mysql (load a backup into the restore database).
 * Kept in one class so tests replace it; nothing here touches the live database's rows.
 * Every command runs from an argv array or with escapeshellarg, never with user input in a shell string.
 */
class BackupShell
{
    /** File names in one remote folder, e.g. db/hourly. Empty when rclone is not configured. */
    public function remoteList(string $folder): array
    {
        $remote = config('backup.remote');
        if (! $remote) {
            return [];
        }

        $result = Process::timeout(60)->env($this->rcloneEnv())
            ->run([config('backup.rclone_binary'), 'lsf', rtrim($remote, '/').'/'.$folder, '--files-only']);

        if (! $result->successful()) {
            throw new RuntimeException('Could not list the off-site backups: '.$this->firstLine($result->errorOutput()));
        }

        return array_values(array_filter(array_map('trim', explode("\n", $result->output()))));
    }

    /** Download one remote file into $dir; returns the local path. */
    public function remoteDownload(string $folder, string $file, string $dir): string
    {
        File::ensureDirectoryExists($dir);
        $remote = rtrim((string) config('backup.remote'), '/').'/'.$folder.'/'.$file;

        $result = Process::timeout(600)->env($this->rcloneEnv())
            ->run([config('backup.rclone_binary'), 'copy', $remote, $dir, '--retries', '3']);

        $path = $dir.DIRECTORY_SEPARATOR.$file;
        if (! $result->successful() || ! File::exists($path)) {
            throw new RuntimeException('Could not download '.$folder.'/'.$file.': '.$this->firstLine($result->errorOutput()));
        }

        return $path;
    }

    /** Run the off-site backup script once (the "Back up now" button). */
    public function runBackupScript(): string
    {
        $result = Process::timeout(900)->env($this->rcloneEnv())
            ->run(['bash', config('backup.script'), 'hourly']);

        if (! $result->successful()) {
            throw new RuntimeException('Backup failed: '.$this->firstLine($result->errorOutput() ?: $result->output()));
        }

        return trim($result->output());
    }

    /**
     * gzip-compressed dump of the given live tables to $path, verified complete. Used as the safety copy
     * taken before any restore writes, so the restore can be undone.
     */
    public function dumpTables(array $tables, string $path): int
    {
        File::ensureDirectoryExists(dirname($path));
        $c = $this->liveConnectionConfig();

        $cmd = implode(' ', array_map('escapeshellarg', array_merge(
            [config('backup.mysqldump_binary'), '-h', $c['host'], '-P', (string) $c['port'], '-u', $c['username'],
                '--single-transaction', '--quick', '--lock-tables=false', $c['database']],
            $tables,
        ))).' | gzip > '.escapeshellarg($path);

        $result = Process::timeout(600)->env(['MYSQL_PWD' => (string) $c['password']])->run(['bash', '-o', 'pipefail', '-c', $cmd]);
        if (! $result->successful()) {
            throw new RuntimeException('Safety copy failed: '.$this->firstLine($result->errorOutput()));
        }

        $this->assertDumpComplete($path);

        return (int) File::size($path);
    }

    /** Empty the restore database and load a .sql.gz dump into it. */
    public function loadIntoRestoreDatabase(string $dumpPath, string $database): void
    {
        $this->assertDumpComplete($dumpPath);
        $c = $this->liveConnectionConfig();

        $cmd = 'zcat '.escapeshellarg($dumpPath).' | '.implode(' ', array_map('escapeshellarg',
            [config('backup.mysql_binary'), '-h', $c['host'], '-P', (string) $c['port'], '-u', $c['username'], $database]));

        $result = Process::timeout(900)->env(['MYSQL_PWD' => (string) $c['password']])->run(['bash', '-o', 'pipefail', '-c', $cmd]);
        if (! $result->successful()) {
            throw new RuntimeException('Loading the backup failed: '.$this->firstLine($result->errorOutput()));
        }
    }

    /** mysqldump writes "-- Dump completed" as its very last line; anything else is a cut-off file. */
    public function assertDumpComplete(string $path): void
    {
        $result = Process::timeout(120)->run(['bash', '-o', 'pipefail', '-c', 'zcat '.escapeshellarg($path).' | tail -n 1']);

        if (! $result->successful() || ! str_contains($result->output(), '-- Dump completed')) {
            throw new RuntimeException('The backup file '.basename($path).' is incomplete or unreadable.');
        }
    }

    private function liveConnectionConfig(): array
    {
        $c = config('database.connections.'.config('database.default'));

        return [
            'host' => $c['host'] ?? '127.0.0.1',
            'port' => $c['port'] ?? 3306,
            'username' => $c['username'] ?? '',
            'password' => $c['password'] ?? '',
            'database' => $c['database'] ?? '',
        ];
    }

    private function rcloneEnv(): array
    {
        return array_filter(['RCLONE_CONFIG' => config('backup.rclone_config')]);
    }

    private function firstLine(string $text): string
    {
        $line = trim(strtok(trim($text), "\n") ?: '');

        return $line !== '' ? mb_substr($line, 0, 300) : 'no details';
    }
}
