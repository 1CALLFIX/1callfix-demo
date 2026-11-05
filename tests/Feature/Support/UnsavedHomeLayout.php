<?php

namespace Tests\Feature\Support;

use App\Models\Setting;

/**
 * The 2026-11-05 migrations save a homepage order and the Parcel card's switch and place. Tests of the older defaults
 * (parcel card, position settings) call this first to get a database where nothing was ever saved.
 */
final class UnsavedHomeLayout
{
    public static function apply(): void
    {
        foreach (['home.layout.order', 'home.card.parcel.show', 'home.card.parcel.order', 'home.section.parcel_card.show'] as $key) {
            Setting::clear($key, 'global', null);
        }
        request()->attributes->replace([]);
    }
}
