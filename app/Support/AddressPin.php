<?php

namespace App\Support;

/**
 * Owner 2026-11-07: a customer address must carry its real spot, so a professional or rider goes to the door and not
 * to the middle of the service area. When the map is available (Google Maps key configured), the add-address forms
 * require a pin (map or "Use my current location"); without a key they keep the old area-centre fallback.
 */
final class AddressPin
{
    public static function required(): bool
    {
        return (bool) config('services.google_maps.key');
    }
}
