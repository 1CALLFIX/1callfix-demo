<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Same one page as AC for every other appliance (owner 2026-11-14: "for all these types of services"): Washing
 * Machine, Fridge, TV, Geyser, Inverter, Mixie, Water Purifier, Air Cooler, Chimney show 1 Service, 2 Repair,
 * 3 Install & move, each item with an Add button. Only services in the Appliance category that have no section yet;
 * install / un-install / shifting -> Install & move, repair -> Repair, the rest -> Service. Each section gets its own
 * sort number (lowest of the subcategory + 0 / 1 / 2) so the order holds even when every number is the same.
 * Prices untouched. Running it twice changes nothing.
 */
return new class extends Migration
{
    private const ORDER = ['Service' => 0, 'Repair' => 1, 'Install & move' => 2];

    public function up(): void
    {
        $categoryIds = DB::table('service_categories')->where('name', 'like', 'Appliance%')->pluck('id');
        $subcategoryIds = DB::table('service_subcategories')->whereIn('category_id', $categoryIds)->pluck('id');

        foreach ($subcategoryIds as $subcategoryId) {
            $services = DB::table('services')->where('subcategory_id', $subcategoryId)->whereNull('deleted_at')
                ->get(['id', 'name', 'sort_order', 'page_section', 'choice_of']);

            if ($services->isEmpty() || $services->whereNotNull('page_section')->isNotEmpty()) {
                continue; // AC (already done) or set up by hand
            }

            $byId = $services->keyBy('id');
            $sectionOf = function ($service) use (&$sectionOf, $byId): string {
                if ($service->choice_of && $byId->has($service->choice_of)) {
                    return $sectionOf($byId->get($service->choice_of));
                }
                $name = strtolower((string) $service->name);

                return match (true) {
                    str_contains($name, 'install') || str_contains($name, 'shift') => 'Install & move',
                    str_contains($name, 'repair') => 'Repair',
                    default => 'Service',
                };
            };

            $base = (int) $services->min('sort_order');
            foreach ($services as $service) {
                $section = $sectionOf($service);
                DB::table('services')->where('id', $service->id)
                    ->update(['page_section' => $section, 'sort_order' => $base + self::ORDER[$section]]);
            }
        }
    }

    public function down(): void
    {
        // Display only; the column itself goes with 2026_11_17_100000's rollback.
    }
};
