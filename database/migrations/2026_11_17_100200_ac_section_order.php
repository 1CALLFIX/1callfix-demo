<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix for 2026_11_17_100100 (owner 2026-11-14): on production every service had the same sort number (0), so
 * reshuffling the numbers changed nothing and the AC sections showed in name order (Repair & gas first). Here each
 * AC section gets its own sort number, lowest number of the subcategory + 0 / 1 / 2, so the page always shows
 * 1 Service, 2 Repair & gas, 3 Install & move. Only AC services that have one of these three sections move.
 * Admins can still change the order afterwards (Reorder). Running it twice changes nothing.
 */
return new class extends Migration
{
    private const ORDER = ['Service' => 0, 'Repair & gas' => 1, 'Install & move' => 2];

    public function up(): void
    {
        $subcategoryIds = DB::table('service_subcategories')
            ->whereIn(DB::raw('LOWER(TRIM(name))'), ['split ac', 'window ac', 'other ac'])
            ->pluck('id');

        foreach ($subcategoryIds as $subcategoryId) {
            $services = DB::table('services')->where('subcategory_id', $subcategoryId)->whereNull('deleted_at')
                ->whereIn('page_section', array_keys(self::ORDER))->get(['id', 'page_section', 'sort_order']);

            if ($services->isEmpty()) {
                continue;
            }

            $base = (int) $services->min('sort_order');
            foreach ($services as $service) {
                DB::table('services')->where('id', $service->id)
                    ->update(['sort_order' => $base + self::ORDER[$service->page_section]]);
            }
        }
    }

    public function down(): void
    {
        // Display order only; nothing to undo.
    }
};
