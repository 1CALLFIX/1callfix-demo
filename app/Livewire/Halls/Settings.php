<?php

namespace App\Livewire\Halls;

use App\Livewire\MarketplaceOrders\Settings as ShopSettings;
use App\Support\HallSettings;

/** Admin → Hall Settings: the Shop Settings page (same scopes, validation, audit log, Super Admin only) over HallSettings. */
class Settings extends ShopSettings
{
    protected function registry(): string
    {
        return HallSettings::class;
    }

    protected function pageTitle(): string
    {
        return 'Hall Settings';
    }
}
