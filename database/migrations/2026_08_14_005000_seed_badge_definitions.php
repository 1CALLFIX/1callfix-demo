<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The mission's own required example set. NEW is the one badge with a real,
// non-invented automatic rule (recently_created, admin-editable
// within_days) -- everything else ships 'manual' because no existing
// popularity/trending statistics engine exists to honestly drive an
// automatic rule for them (see badges table's own migration docblock).
// Colors are visually distinct placeholder defaults, not a brand decision
// -- every value here is editable from the admin screen this same slice
// adds, nothing is hardcoded in application code.
return new class extends Migration
{
    private const BADGES = [
        ['key' => 'new', 'label' => 'NEW', 'description' => 'Recently added to the catalog.', 'text_color' => '#ffffff', 'bg_color' => '#16a34a', 'priority' => 100, 'mode' => 'automatic', 'rule_type' => 'recently_created', 'rule_config' => ['within_days' => 14], 'default_duration_days' => null],
        ['key' => 'popular', 'label' => 'POPULAR', 'description' => 'Manually highlighted as a popular choice.', 'text_color' => '#ffffff', 'bg_color' => '#ea580c', 'priority' => 90, 'mode' => 'manual', 'rule_type' => null, 'rule_config' => null, 'default_duration_days' => 30],
        ['key' => 'trending', 'label' => 'TRENDING', 'description' => 'Manually highlighted as trending.', 'text_color' => '#ffffff', 'bg_color' => '#db2777', 'priority' => 85, 'mode' => 'manual', 'rule_type' => null, 'rule_config' => null, 'default_duration_days' => 14],
        ['key' => 'featured', 'label' => 'FEATURED', 'description' => 'Manually curated placement.', 'text_color' => '#ffffff', 'bg_color' => '#7c3aed', 'priority' => 80, 'mode' => 'manual', 'rule_type' => null, 'rule_config' => null, 'default_duration_days' => 30],
        ['key' => 'best_value', 'label' => 'BEST VALUE', 'description' => 'Manually flagged as strong value for money.', 'text_color' => '#ffffff', 'bg_color' => '#0891b2', 'priority' => 70, 'mode' => 'manual', 'rule_type' => null, 'rule_config' => null, 'default_duration_days' => 30],
        ['key' => 'limited', 'label' => 'LIMITED', 'description' => 'Limited-time or limited-availability offering.', 'text_color' => '#ffffff', 'bg_color' => '#dc2626', 'priority' => 95, 'mode' => 'manual', 'rule_type' => null, 'rule_config' => null, 'default_duration_days' => 7],
        ['key' => 'flash_sale', 'label' => 'FLASH SALE', 'description' => 'Active flash sale pricing — see the Flash Sale engine for the sale itself; this badge is the catalog-facing label.', 'text_color' => '#ffffff', 'bg_color' => '#e11d48', 'priority' => 99, 'mode' => 'manual', 'rule_type' => null, 'rule_config' => null, 'default_duration_days' => null],
    ];

    public function up(): void
    {
        $now = now();

        DB::table('badges')->insert(array_map(fn ($b) => [
            'key' => $b['key'], 'label' => $b['label'], 'description' => $b['description'],
            'text_color' => $b['text_color'], 'bg_color' => $b['bg_color'], 'priority' => $b['priority'],
            'mode' => $b['mode'], 'rule_type' => $b['rule_type'],
            'rule_config' => $b['rule_config'] ? json_encode($b['rule_config']) : null,
            'default_duration_days' => $b['default_duration_days'], 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ], self::BADGES));
    }

    public function down(): void
    {
        DB::table('badges')->whereIn('key', array_column(self::BADGES, 'key'))->delete();
    }
};
