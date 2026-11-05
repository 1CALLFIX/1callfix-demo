<?php

namespace Tests\Feature\Support;

use App\Models\Setting;

/**
 * The 2026-11-05 migration saves a homepage order and switches the Parcel card off. Tests of the older defaults
 * (parcel card, position settings) call this first to get a database where nothing was ever saved.
 */
final class UnsavedHomeLayout
{
    public static function apply(): void
    {
        foreach (['home.layout.order', 'home.card.parcel.show', 'home.section.parcel_card.show'] as $key) {
            Setting::clear($key, 'global', null);
        }
        request()->attributes->replace([]);
    }
}
