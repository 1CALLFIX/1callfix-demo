<?php

namespace App\Services\Qa;

use Illuminate\Support\Facades\Storage;

/**
 * Tracks every record qa:seed creates, in creation order, as
 * [table => [ids...]] — persisted to storage/app/qa-seed-manifest.json.
 * qa:clean reads this and deletes in REVERSE order (children before
 * parents), rather than relying on cascade-delete alone, so cleanup is
 * exact and auditable: only what this run actually created, nothing
 * matched by a naming convention that could theoretically collide with
 * real data.
 */
class QaManifest
{
    private const PATH = 'qa-seed-manifest.json';

    /** @var array<string, array<int>> */
    private array $entries = [];

    /** @var array<int, string> insertion order of table names, for reverse-order cleanup */
    private array $tableOrder = [];

    public function record(string $table, int|array $ids): void
    {
        $ids = is_array($ids) ? $ids : [$ids];
        if (! isset($this->entries[$table])) {
            $this->entries[$table] = [];
            $this->tableOrder[] = $table;
        }
        array_push($this->entries[$table], ...$ids);
    }

    public function save(): void
    {
        Storage::disk('local')->put(self::PATH, json_encode([
            'created_at' => now()->toIso8601String(),
            'app_env' => app()->environment(),
            'table_order' => $this->tableOrder,
            'entries' => $this->entries,
        ], JSON_PRETTY_PRINT));
    }

    public static function exists(): bool
    {
        return Storage::disk('local')->exists(self::PATH);
    }

    /** @return array{created_at:string,app_env:string,table_order:array<int,string>,entries:array<string,array<int>>} */
    public static function load(): array
    {
        return json_decode(Storage::disk('local')->get(self::PATH), true);
    }

    public static function delete(): void
    {
        Storage::disk('local')->delete(self::PATH);
    }

    public function totalRecords(): int
    {
        return array_sum(array_map('count', $this->entries));
    }

    public function counts(): array
    {
        return array_map('count', $this->entries);
    }
}
