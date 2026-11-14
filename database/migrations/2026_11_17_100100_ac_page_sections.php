<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Puts the live AC services into their page sections (owner 2026-11-14):
 *   Service        — cleaning / service (the Split AC Service choice card, Window AC Service, ...)
 *   Repair & gas   — repair, gas top-up, gas charging
 *   Install & move — install, un-install, shifting
 * Only the Split AC / Window AC / Other AC subcategories, only services with no section yet. Within each subcategory
 * the services' own sort_order numbers are reshuffled among themselves so the sections appear in that order; no other
 * service moves. Running it twice changes nothing.
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
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'sort_order', 'page_section', 'choice_of']);

            if ($services->isEmpty() || $services->whereNotNull('page_section')->isNotEmpty()) {
                continue; // already set up (by hand or by an earlier run)
            }

            $byId = $services->keyBy('id');
            $sectionOf = function ($service) use (&$sectionOf, $byId): string {
                // A choice button sits wherever its main card sits.
                if ($service->choice_of && $byId->has($service->choice_of)) {
                    return $sectionOf($byId->get($service->choice_of));
                }
                $name = strtolower((string) $service->name);

                return match (true) {
                    str_contains($name, 'install') || str_contains($name, 'shift') => 'Install & move',
                    str_contains($name, 'repair') || str_contains($name, 'gas') => 'Repair & gas',
                    default => 'Service',
                };
            };

            $slots = $services->pluck('sort_order')->sort()->values();
            $ordered = $services->sortBy(fn ($s) => [self::ORDER[$sectionOf($s)], $s->sort_order, $s->name])->values();

            foreach ($ordered as $i => $service) {
                DB::table('services')->where('id', $service->id)->update([
                    'page_section' => $sectionOf($service),
                    'sort_order' => $slots[$i],
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Sections are display only; the column itself goes with the previous migration's rollback.
    }
};
