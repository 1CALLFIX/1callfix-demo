<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-11-05: top banner first, then the business doors, Most booked, Best offers near you, then a card for
 * every live business (services, shop, food). The Parcel card goes (parcel gets a banner instead).
 * Settings only; the Super Admin can still change all of it in Admin → Growth → Homepage sections.
 */
return new class extends Migration
{
    private const ORDER = [
        'hero_banner', 'doors', 'most_booked', 'offers', 'module_cards', 'categories', 'new',
        'shop_categories', 'shops', 'shop_products', 'mid_banner_1', 'collections', 'mid_banner_2',
        'mid_banner_3', 'membership', 'reviews', 'trust', 'faq', 'parcel_card',
    ];

    private const VALUES = [
        'home.card.parcel.show' => '0',
        'home.section.parcel_card.show' => '0',
    ];

    public function up(): void
    {
        $this->put('home.layout.order', implode(',', self::ORDER));
        foreach (self::VALUES as $key => $value) {
            $this->put($key, $value);
        }
    }

    public function down(): void
    {
        // Settings change only; the previous order is in the pre-deploy database backup.
    }

    private function put(string $key, string $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['scope_type' => 'global', 'scope_id' => null, 'key' => $key],
            ['value' => $value, 'updated_at' => now(), 'created_at' => now()],
        );
        cache()->forget("setting:global::{$key}");
    }
};
