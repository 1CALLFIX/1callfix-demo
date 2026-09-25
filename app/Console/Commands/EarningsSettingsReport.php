<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Support\EarningsSettings;
use Illuminate\Console\Command;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — 2.7. READ-ONLY pre-deploy report: every
 * key the Earnings feature reads, its resolved value at GLOBAL scope (UNSET
 * where null — which since Stage 2 means OFF), how many scoped overrides
 * exist, and a plain SELECT to run against production's `settings` table
 * BEFORE deploying, so every value that must be saved first is known.
 */
class EarningsSettingsReport extends Command
{
    protected $signature = 'earnings:settings-report';

    protected $description = 'Read-only: prints every Earnings setting key, its global value (UNSET = off) and a production SELECT for the same keys';

    public function handle(): int
    {
        $rows = [];
        foreach (EarningsSettings::KEYS as $key => $editedIn) {
            $global = Setting::where('scope_type', 'global')->whereNull('scope_id')->where('key', $key)->value('value');
            $overrides = Setting::where('key', $key)->where('scope_type', '!=', 'global')->count();

            $rows[] = [
                $key,
                ($global === null || trim((string) $global) === '') ? 'UNSET' : $global,
                $overrides,
                $editedIn,
            ];
        }

        $this->table(['key', 'global value', 'scoped overrides', 'edited in'], $rows);

        $this->newLine();
        $this->line('-- Read-only. Run on production BEFORE deploy (MySQL):');
        $this->line(self::productionSql());

        return self::SUCCESS;
    }

    public static function productionSql(): string
    {
        $keys = implode(",\n  ", array_map(fn ($k) => "'{$k}'", array_keys(EarningsSettings::KEYS)));

        return "SELECT scope_type, scope_id, `key`, value, updated_at\nFROM settings\nWHERE `key` IN (\n  {$keys}\n)\nORDER BY `key`, scope_type, scope_id;";
    }
}
