<?php

use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Services\Catalog\CategorySplitter;
use Illuminate\Database\Migrations\Migration;

/**
 * Owner 2026-11-22: the previous app clubbed services into combined categories; "make them separate".
 *
 *   "Appliance | AC Repair"      → AC Repair (Split AC, Window AC, Other AC)  +  Appliance Repair (the other nine)
 *   "Laptop| CCTV | IT Services" → Laptop Repair + Computer Repair (Desktop, Printer) + CCTV + IT & Networking
 *                                  (Networking, Wi-Fi Setup, OnCall Support, Breakdown Help)
 *   subcategories "Mens Salon | Beauty Care" → Men's Salon, "Womens Salon | Beauty Care" → Women's Salon,
 *                 "DJ | Lighting" → DJ & Lighting
 *
 * Found by slug, so a database without these rows (tests, a fresh install) is left alone. Old category slugs 301 to
 * the kept row; partners with the old skill get every new part (CategorySplitter). Data only: down() does nothing —
 * restore the pre-deploy backup to undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $splitter = app(CategorySplitter::class);
        $changed = false;
        $find = fn (string $slug) => ServiceCategory::query()->where('module', 'service')->where('slug', $slug)->first();

        if ($c = $find('appliance-ac-repair')) {
            $changed = true;
            $splitter->split($c, 'AC Repair', [
                'Appliance Repair' => ['washing-machine', 'refrigeratorfridge', 'tv-repair', 'geyser-repair', 'inverter-repair',
                    'grindermixie-repair', 'water-purifier', 'air-cooler-repair', 'chimney'],
            ]);
        }

        if ($c = $find('laptop-cctv-it-services')) {
            $changed = true;
            $splitter->split($c, 'Laptop Repair', [
                'Computer Repair' => ['desktop-repair', 'printer-service'],
                'CCTV' => ['cctv-service'],
                'IT & Networking' => ['networking', 'wi-fi-setup', 'oncall-support', 'breakdown-help'],
            ]);
        }

        foreach (['mens-salon-beauty-care' => "Men's Salon", 'womens-salon-beauty-care' => "Women's Salon", 'dj-lighting' => 'DJ & Lighting'] as $slug => $name) {
            $sub = ServiceSubcategory::query()->where('slug', $slug)->whereHas('category', fn ($q) => $q->where('module', 'service'))->first();
            if ($sub && str_contains($sub->name, '|')) {
                $splitter->renameSubcategory($sub, $name);
            }
        }

        if ($changed) {
            $splitter->renumber('service');
        }
    }

    public function down(): void
    {
        // Data only. Restore the pre-deploy backup to undo.
    }
};
