<?php

namespace App\Services\Operations;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * STEP 5 of the Clear Data tool -- a durable, append-only record of every
 * clear attempt, kept OUTSIDE the database entirely (storage/logs/
 * data-clear.log, one JSON object per line) so it survives regardless of
 * what the operation itself does to the database -- including a bug or a
 * broader-than-intended scope selection that might otherwise wipe out
 * activity_log alongside everything else. This file is the one thing that
 * MUST survive; activity_log (written separately by DataClearService,
 * once an operation completes) is a normal-admin-browsable mirror, not
 * the authoritative record.
 *
 * recordAttempt() is called BEFORE the backup or the clear itself runs --
 * "before it executes, not after" (per the approved design) means even a
 * crash mid-operation (the process dying between the backup step and the
 * delete, say) leaves a line on disk describing exactly what was about to
 * happen: who, what tables, how many rows, from what IP, when.
 * recordOutcome() appends a SEPARATE line once the outcome is known --
 * never edits or removes the attempt line, so the file is a true
 * append-only history, not a mutable status field.
 */
class DataClearAuditLogger
{
    private const LOG_RELATIVE_PATH = 'data-clear.log';

    public function recordAttempt(User $actor, array $moduleKeys, array $tables, array $rowCountsBefore, string $ip): string
    {
        $operationId = (string) Str::uuid();

        $this->append([
            'operation_id' => $operationId,
            'phase' => 'attempted',
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'modules' => $moduleKeys,
            'tables' => $tables,
            'row_counts_before' => $rowCountsBefore,
            'ip' => $ip,
            'at' => now()->toIso8601String(),
        ]);

        return $operationId;
    }

    public function recordOutcome(string $operationId, string $phase, array $extra = []): void
    {
        $this->append(array_merge([
            'operation_id' => $operationId,
            'phase' => $phase,
            'at' => now()->toIso8601String(),
        ], $extra));
    }

    private function append(array $entry): void
    {
        $path = $this->logPath();
        File::ensureDirectoryExists(dirname($path));
        File::append($path, json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    public function logPath(): string
    {
        return storage_path('logs/'.self::LOG_RELATIVE_PATH);
    }

    /** @return array<int,array<string,mixed>> every entry, oldest first -- test/admin-review convenience, not used by the clear flow itself. */
    public function readAll(): array
    {
        $path = $this->logPath();
        if (! File::exists($path)) {
            return [];
        }

        return collect(preg_split('/\R/', File::get($path)))
            ->filter(fn ($line) => trim($line) !== '')
            ->map(fn ($line) => json_decode($line, true))
            ->values()
            ->all();
    }
}
