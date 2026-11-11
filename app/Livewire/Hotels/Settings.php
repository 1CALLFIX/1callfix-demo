<?php

namespace App\Livewire\Hotels;

use App\Livewire\MarketplaceOrders\Settings as ShopSettings;
use App\Support\HotelSettings;

/** Admin → Hotel Settings: the Shop Settings page (same scopes, validation, audit log, Super Admin only) over HotelSettings. */
class Settings extends ShopSettings
{
    protected function registry(): string
    {
        return HotelSettings::class;
    }

    protected function pageTitle(): string
    {
        return 'Hotel Settings';
    }
}
