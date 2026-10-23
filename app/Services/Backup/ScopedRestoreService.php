<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * REF 1CF-BACKUP-RESTORE-001 — "Restore one" franchise / city / store / provider from a backup.
 *
 * The chosen backup is loaded into a separate restore database (never over the live one). This class
 * compares the records that belonged to the chosen item AT BACKUP TIME with the live database and, on
 * request, brings back what is missing. Owner decision 2026-10-23: the default never overwrites newer
 * data and never touches money.
 *
 *  - Which records belong to the item is worked out from the schema, so new modules are covered without
 *    a code change: the item's own row; every table with its foreign key (franchise_id, city_id, …);
 *    for a city also its franchises; then one level down, every table pointing at those rows
 *    (bookings -> booking_id tables, …).
 *  - missing  = in the backup, gone now        -> inserted again with the same id
 *    archived = in the backup, now soft-deleted -> deleted_at cleared
 *    changed  = in both, different values       -> shown only; restored one row at a time on request
 *    new      = created after the backup         -> never touched
 *  - Money tables (payments, wallets, ledger, payouts, commissions, refunds, waivers, …) are compared and
 *    listed but never written: any fix goes through the manual money approval model (CLAUDE.md).
 *  - Platform-wide and system tables (users, roles, settings, audit log, queues, sessions) are out of scope.
 */
class ScopedRestoreService
{
    public const TYPES = [
        'franchise' => ['table' => 'franchises', 'fk' => 'franchise_id', 'label' => 'Franchise'],
        'city' => ['table' => 'cities', 'fk' => 'city_id', 'label' => 'City'],
        'store' => ['table' => 'stores', 'fk' => 'store_id', 'label' => 'Store / restaurant'],
        'provider' => ['table' => 'providers', 'fk' => 'provider_id', 'label' => 'Provider'],
    ];

    public const MONEY_PATTERN = '/payment|wallet|ledger|payout|commission|refund|settle|receivable|waiver|referral_balance|invoice|earning|transaction|cashback|charge/';

    public const SKIP_TABLES = [
        'migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions',
        'password_reset_tokens', 'personal_access_tokens', 'notifications', 'otps',
        'users', 'roles', 'permissions', 'permission_role', 'role_assignments', 'settings',
        'activity_logs', 'data_restores', '_restore_snapshot', 'slug_redirects',
    ];

    /** Columns whose difference alone does not make a row "changed". */
    private const IGNORED_DIFF_COLUMNS = ['updated_at'];

    private const CHANGED_DETAIL_LIMIT = 50;

    private ?array $liveTables = null;

    private ?array $snapshotTables = null;

    private array $columnCache = [];

    public function __construct(private string $snapshot = 'restore', private ?string $live = null)
    {
        $this->live ??= config('database.default');
    }

    public static function isMoneyTable(string $table): bool
    {
        return (bool) preg_match(self::MONEY_PATTERN, $table);
    }

    /** Name of the item in the backup (it may no longer exist live). */
    public function labelFor(string $type, int $id): ?string
    {
        $table = self::TYPES[$type]['table'] ?? throw new RuntimeException('Unknown type.');
        $row = DB::connection($this->snapshot)->table($table)->where('id', $id)->first();

        return $row ? ($row->name ?? $row->business_name ?? ('#'.$id)) : null;
    }

    /**
     * The records that belonged to the item at backup time, as [table => [column, values]].
     *
     * @return array<string, array{0: string, 1: list<int|string>}>
     */
    public function scope(string $type, int $id): array
    {
        $def = self::TYPES[$type] ?? throw new RuntimeException('Unknown type.');
        $snap = DB::connection($this->snapshot);

        if (! $snap->table($def['table'])->where('id', $id)->exists()) {
            throw new RuntimeException('This '.strtolower($def['label']).' did not exist at the time of the chosen backup.');
        }

        $scope = [$def['table'] => ['id', [$id]]];
        $parents = [$def['fk'] => [$id]];

        if ($type === 'city') {
            $franchiseIds = $snap->table('franchises')->where('city_id', $id)->pluck('id')->all();
            if ($franchiseIds) {
                $scope['franchises'] = ['id', $franchiseIds];
                $parents['franchise_id'] = $franchiseIds;
            }
        }

        // Level 1: every table carrying the item's foreign key.
        $level1 = [];
        foreach ($this->candidateTables() as $table) {
            if (isset($scope[$table])) {
                continue;
            }
            foreach ($parents as $column => $values) {
                if ($this->hasColumn($table, $column)) {
                    $scope[$table] = [$column, $values];
                    $level1[] = $table;
                    break;
                }
            }
        }

        // Level 2: tables pointing at the level-1 rows (bookings -> booking_id, zones -> zone_id, …).
        foreach ($level1 as $parentTable) {
            if (! $this->hasColumn($parentTable, 'id')) {
                continue;
            }
            [$column, $values] = $scope[$parentTable];
            $ids = $snap->table($parentTable)->whereIn($column, $values)->pluck('id')->all();
            if ($ids === []) {
                continue;
            }
            $childColumn = Str::singular($parentTable).'_id';
            foreach ($this->candidateTables() as $table) {
                if (! isset($scope[$table]) && $this->hasColumn($table, $childColumn)) {
                    $scope[$table] = [$childColumn, $ids];
                }
            }
        }

        return $scope;
    }

    /**
     * @return array{tables: list<array>, totals: array{missing: int, archived: int, changed: int, new: int, money: int}}
     */
    public function compare(string $type, int $id): array
    {
        $tables = [];
        $totals = ['missing' => 0, 'archived' => 0, 'changed' => 0, 'new' => 0, 'money' => 0];

        foreach ($this->scope($type, $id) as $table => [$column, $values]) {
            $diff = $this->diffTable($table, $column, $values);
            if ($diff['missing'] + $diff['archived'] + $diff['changed'] + $diff['new'] === 0) {
                continue;
            }

            $money = self::isMoneyTable($table);
            $tables[] = ['table' => $table, 'money' => $money] + array_diff_key($diff, ['missing_rows' => 1, 'archived_ids' => 1]);

            if ($money) {
                $totals['money'] += $diff['missing'] + $diff['archived'] + $diff['changed'];
            } else {
                foreach (['missing', 'archived', 'changed'] as $k) {
                    $totals[$k] += $diff[$k];
                }
            }
            $totals['new'] += $diff['new'];
        }

        return ['tables' => $tables, 'totals' => $totals];
    }

    /** Tables a "restore missing" will write to (for the safety copy). */
    public function tablesToWrite(string $type, int $id): array
    {
        $out = [];
        foreach ($this->scope($type, $id) as $table => [$column, $values]) {
            if (self::isMoneyTable($table)) {
                continue;
            }
            $diff = $this->diffTable($table, $column, $values);
            if ($diff['missing'] + $diff['archived'] > 0) {
                $out[] = $table;
            }
        }

        return $out;
    }

    /**
     * Insert the missing rows again (same ids) and clear deleted_at on archived ones. Money tables are
     * skipped. One transaction: either everything comes back or nothing changes.
     *
     * @return array{inserted: array<string, int>, undeleted: array<string, int>}
     */
    public function restoreMissing(string $type, int $id): array
    {
        $work = [];
        foreach ($this->scope($type, $id) as $table => [$column, $values]) {
            if (self::isMoneyTable($table)) {
                continue;
            }
            $diff = $this->diffTable($table, $column, $values);
            if ($diff['missing'] + $diff['archived'] > 0) {
                $work[$table] = $diff;
            }
        }

        $result = ['inserted' => [], 'undeleted' => []];
        $live = DB::connection($this->live);

        $live->transaction(function () use ($work, $live, &$result) {
            foreach ($this->parentFirst(array_keys($work)) as $table) {
                $diff = $work[$table];
                foreach (array_chunk($diff['missing_rows'], 200) as $chunk) {
                    $live->table($table)->insert($chunk);
                }
                if ($diff['missing_rows']) {
                    $result['inserted'][$table] = count($diff['missing_rows']);
                }
                if ($diff['archived_ids']) {
                    $live->table($table)->whereIn('id', $diff['archived_ids'])->update(['deleted_at' => null]);
                    $result['undeleted'][$table] = count($diff['archived_ids']);
                }
            }
        });

        return $result;
    }

    /** Put one changed row back to its backup values (non-money tables in the item's scope only). */
    public function restoreRow(string $type, int $id, string $table, int $rowId): array
    {
        $scope = $this->scope($type, $id);
        if (! isset($scope[$table]) || self::isMoneyTable($table) || ! $this->hasColumn($table, 'id')) {
            throw new RuntimeException('That record cannot be restored from here.');
        }

        [$column, $values] = $scope[$table];
        $then = DB::connection($this->snapshot)->table($table)->whereIn($column, $values)->where('id', $rowId)->first();
        if (! $then) {
            throw new RuntimeException('That record is not part of this backup.');
        }

        $columns = array_values(array_diff($this->sharedColumns($table), ['id']));
        $values = array_intersect_key((array) $then, array_flip($columns));
        $updated = DB::connection($this->live)->table($table)->where('id', $rowId)->update($values);
        if ($updated === 0 && ! DB::connection($this->live)->table($table)->where('id', $rowId)->exists()) {
            throw new RuntimeException('That record no longer exists; use "Restore missing records" instead.');
        }

        return $values;
    }

    /**
     * @return array{missing: int, archived: int, changed: int, new: int, missing_rows: list<array>, archived_ids: list<int>, changed_rows: list<array>}
     */
    private function diffTable(string $table, string $column, array $values): array
    {
        $empty = ['missing' => 0, 'archived' => 0, 'changed' => 0, 'new' => 0, 'missing_rows' => [], 'archived_ids' => [], 'changed_rows' => []];
        if (! $this->liveHas($table) || ! $this->hasColumn($table, 'id')) {
            return $empty;
        }

        $columns = $this->sharedColumns($table);
        $softDeletes = in_array('deleted_at', $columns, true);
        $out = $empty;
        $snapshotIds = [];

        foreach (array_chunk($values, 1000) as $valueChunk) {
            $thenRows = DB::connection($this->snapshot)->table($table)->select($columns)->whereIn($column, $valueChunk)->get();
            if ($thenRows->isEmpty()) {
                continue;
            }
            $ids = $thenRows->pluck('id')->all();
            array_push($snapshotIds, ...$ids);
            $nowRows = DB::connection($this->live)->table($table)->select($columns)->whereIn('id', $ids)->get()->keyBy('id');

            foreach ($thenRows as $then) {
                $now = $nowRows->get($then->id);
                if ($now === null) {
                    $out['missing_rows'][] = (array) $then;

                    continue;
                }
                if ($softDeletes && $now->deleted_at !== null && $then->deleted_at === null) {
                    $out['archived_ids'][] = $then->id;

                    continue;
                }
                $changed = [];
                foreach ($columns as $c) {
                    if (! in_array($c, self::IGNORED_DIFF_COLUMNS, true) && (string) $then->{$c} !== (string) $now->{$c}) {
                        $changed[$c] = ['then' => $this->short($then->{$c}), 'now' => $this->short($now->{$c})];
                    }
                }
                if ($changed) {
                    $out['changed']++;
                    if (count($out['changed_rows']) < self::CHANGED_DETAIL_LIMIT) {
                        $out['changed_rows'][] = ['id' => $then->id, 'columns' => $changed];
                    }
                }
            }
        }

        $out['missing'] = count($out['missing_rows']);
        $out['archived'] = count($out['archived_ids']);

        if ($this->hasLiveColumn($table, $column)) {
            $new = DB::connection($this->live)->table($table)->whereIn($column, $values);
            foreach (array_chunk($snapshotIds, 1000) as $idChunk) {
                $new->whereNotIn('id', $idChunk);
            }
            $out['new'] = $new->count();
        }

        return $out;
    }

    /** Order tables so a row's parent table is written before it (foreign keys stay on). */
    private function parentFirst(array $tables): array
    {
        $deps = [];
        foreach ($tables as $t) {
            $deps[$t] = [];
            foreach (Schema::connection($this->live)->getForeignKeys($t) as $fk) {
                if ($fk['foreign_table'] !== $t && in_array($fk['foreign_table'], $tables, true)) {
                    $deps[$t][] = $fk['foreign_table'];
                }
            }
        }

        $ordered = [];
        $visiting = [];
        $visit = function (string $t) use (&$visit, &$ordered, &$visiting, $deps) {
            if (isset($ordered[$t]) || isset($visiting[$t])) {
                return;
            }
            $visiting[$t] = true;
            foreach ($deps[$t] as $parent) {
                $visit($parent);
            }
            $ordered[$t] = true;
        };
        foreach ($tables as $t) {
            $visit($t);
        }

        return array_keys($ordered);
    }

    /** Tables present in the backup that may hold an item's records. */
    private function candidateTables(): array
    {
        return array_values(array_filter($this->snapshotTables(), fn ($t) => ! in_array($t, self::SKIP_TABLES, true)
            && ! str_starts_with($t, '_')
            && $this->liveHas($t)));
    }

    /** Columns that exist in both the backup and live (a backup may be older than the newest migration). */
    private function sharedColumns(string $table): array
    {
        return $this->columnCache['shared.'.$table] ??= array_values(array_intersect(
            Schema::connection($this->snapshot)->getColumnListing($table),
            Schema::connection($this->live)->getColumnListing($table),
        ));
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columnCache['snap.'.$table] ??= Schema::connection($this->snapshot)->getColumnListing($table), true);
    }

    private function hasLiveColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columnCache['live.'.$table] ??= Schema::connection($this->live)->getColumnListing($table), true);
    }

    private function liveHas(string $table): bool
    {
        return in_array($table, $this->liveTables ??= $this->tableListing($this->live), true);
    }

    private function snapshotTables(): array
    {
        return $this->snapshotTables ??= $this->tableListing($this->snapshot);
    }

    private function tableListing(string $connection): array
    {
        $schema = Schema::connection($connection);

        return $schema->getTableListing($schema->getCurrentSchemaListing(), false);
    }

    private function short(mixed $value): ?string
    {
        return $value === null ? null : Str::limit((string) $value, 120);
    }
}
