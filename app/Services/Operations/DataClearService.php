<?php

namespace App\Services\Operations;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Operations "Clear Data" tool — the orchestrator behind the approved
 * safety design (export existing data, then clear tables to test a fresh
 * state, during app upgrades). The highest-stakes tool in this project;
 * every gate below refuses outright rather than warns, and none of them
 * can be bypassed by a flag or a higher permission.
 *
 * run() enforces the approved steps in this order:
 *   1. Environment gate — structurally refuses production, no override.
 *   2. Selection validation — only real DataClearCatalog module keys,
 *      and the resolved tables are re-checked against NEVER_CLEARABLE
 *      here even though the catalog itself should never offer one.
 *   3. Confirmation phrase — exact match against the phrase this run's
 *      caller generated and displayed; checked before the (expensive)
 *      backup step so a mistyped phrase fails fast without taking one.
 *   4. Backup + verify (delegated to DataClearBackupService) — refuses
 *      to proceed on ANY verification failure.
 *   5. Durable audit log entry, written BEFORE the actual clear runs (see
 *      DataClearAuditLogger's own docblock for why).
 *   6. Execute — delete the resolved tables' rows inside one transaction.
 *
 * Every refusal is recorded to the audit log too (a short entry, not the
 * full pre-execution one) — a security-relevant trail of "who tried what
 * and why it didn't proceed" matters here at least as much as the record
 * of what succeeded.
 */
class DataClearService
{
    public function __construct(
        private DataClearBackupService $backup,
        private DataClearAuditLogger $auditLog,
    ) {
    }

    /**
     * A fresh, single-use phrase for the given selection. The caller
     * (the Livewire screen) is responsible for treating this as the ONLY
     * currently-valid phrase and regenerating a new one after every
     * selection change and after every successful run — this method
     * itself has no memory of past phrases, so "single-use" is a property
     * of how the caller uses it, not of this method.
     */
    public function generateConfirmationPhrase(array $moduleKeys): string
    {
        $scope = count($moduleKeys) === 1 ? Str::upper($moduleKeys[0]) : 'MULTI-'.count($moduleKeys);

        return "CLEAR-{$scope}-".Str::upper(Str::random(6));
    }

    /** @return array<string,int> table => current row count, for the running total the screen shows before confirmation. */
    public function affectedRowCounts(array $moduleKeys): array
    {
        $tables = DataClearCatalog::tablesFor($moduleKeys);

        return collect($tables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
    }

    public function run(
        User $actor,
        array $moduleKeys,
        string $typedPhrase,
        string $expectedPhrase,
        string $ip
    ): DataClearRunResult {
        // ---- STEP 1: environment gate ----
        // Checked FIRST, before anything else — including before the
        // selection below is even looked at. No permission check, no
        // --force-equivalent parameter exists anywhere in this class:
        // structurally incapable, not merely discouraged.
        if (app()->environment('production')) {
            return $this->refuse($actor, $ip, [], 'This tool cannot run in a production environment.');
        }

        // ---- STEP 3 (selection half): only real, known modules; never-clearable is re-checked here regardless of the catalog ----
        $moduleKeys = array_values(array_unique($moduleKeys));

        if (empty($moduleKeys)) {
            return $this->refuse($actor, $ip, $moduleKeys, 'Select at least one module to clear.');
        }

        $unknown = array_diff($moduleKeys, DataClearCatalog::allModuleKeys());
        if (! empty($unknown)) {
            return $this->refuse($actor, $ip, $moduleKeys, 'Unknown module(s): '.implode(', ', $unknown).'.');
        }

        $tables = DataClearCatalog::tablesFor($moduleKeys);

        $forbidden = $this->forbiddenTables($tables);
        if (! empty($forbidden)) {
            // Not expected to ever be reachable through the real UI (the
            // catalog never offers these), but this is the actual last
            // line of defense, not the UI — a bug in the catalog must not
            // be able to defeat it.
            return $this->refuse($actor, $ip, $moduleKeys, 'Refused: '.implode(', ', $forbidden).' can never be cleared by this tool.');
        }

        // ---- STEP 4: typed confirmation, checked before the expensive backup step ----
        if (! hash_equals($expectedPhrase, trim($typedPhrase))) {
            return $this->refuse($actor, $ip, $moduleKeys, 'Confirmation phrase did not match.');
        }

        // ---- STEP 5 (part 1): durable record of exactly what's about to happen, written before backup/clear run ----
        $rowCountsBefore = $this->affectedRowCounts($moduleKeys);
        $operationId = $this->auditLog->recordAttempt($actor, $moduleKeys, $tables, $rowCountsBefore, $ip);

        // ---- STEP 2: backup + verify ----
        $backupResult = $this->backup->backupAndVerify($tables);

        if (! $backupResult->success) {
            $this->auditLog->recordOutcome($operationId, 'refused_backup_failed', ['reason' => $backupResult->reason]);

            return DataClearRunResult::refused('Backup verification failed — nothing was cleared. '.$backupResult->reason, $operationId);
        }

        // ---- STEP 6: execute ----
        DB::transaction(function () use ($tables) {
            foreach ($tables as $table) {
                DB::table($table)->delete();
            }
        });

        $rowCountsAfter = collect($tables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();

        $this->auditLog->recordOutcome($operationId, 'completed', [
            'row_counts_before' => $rowCountsBefore,
            'row_counts_after' => $rowCountsAfter,
            'backup_path' => $backupResult->path,
        ]);

        // Mirrored into activity_log for normal admin browsability — the
        // file above (DataClearAuditLogger) is the one that must survive
        // no matter what; this is the convenience copy. No real Eloquent
        // "subject" exists for a database-wide operation like this one, so
        // a synthetic subject_type/subject_id is used, matching how
        // ActivityLogger::log() (not ::logModel()) is meant to be called
        // for exactly this kind of non-model event.
        ActivityLogger::log(
            $actor,
            'DataClearOperation',
            0,
            "Cleared ".array_sum($rowCountsBefore)." row(s) across: ".implode(', ', $moduleKeys),
            [
                'operation_id' => $operationId,
                'modules' => $moduleKeys,
                'tables' => $tables,
                'row_counts_before' => $rowCountsBefore,
                'backup_path' => $backupResult->path,
            ]
        );

        return DataClearRunResult::success($operationId, $rowCountsBefore, $backupResult->path);
    }

    /**
     * The actual code-level safety floor for STEP 3's never-clearable
     * rule, as its own public method rather than inlined into run() --
     * so it's directly testable against an ARBITRARY table list (e.g.
     * ['users', 'bookings']), independent of whether today's
     * DataClearCatalog::MODULES would ever actually produce that
     * combination. A future catalog bug that DID include a never-clearable
     * table would be caught by this same check inside run() -- this
     * method is what proves that check is real, working code, not just an
     * assumption resting on the catalog currently being written correctly.
     *
     * @return array<int,string> the subset of $tables that must never be cleared
     */
    public function forbiddenTables(array $tables): array
    {
        return array_values(array_intersect($tables, DataClearCatalog::NEVER_CLEARABLE));
    }

    private function refuse(User $actor, string $ip, array $moduleKeys, string $reason): DataClearRunResult
    {
        $this->auditLog->recordOutcome(
            (string) Str::uuid(),
            'refused',
            ['actor_id' => $actor->id, 'actor_name' => $actor->name, 'modules' => $moduleKeys, 'ip' => $ip, 'reason' => $reason]
        );

        return DataClearRunResult::refused($reason);
    }
}
