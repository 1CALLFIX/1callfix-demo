<?php

namespace App\Livewire\Halls;

use App\Livewire\MarketplaceOrders\Settings as ShopSettings;
use App\Support\RentalSettings;

/** Admin → Rental Settings: the Shop Settings page (same scopes, validation, audit log, Super Admin only) over RentalSettings (rental kinds + every function hall setting). */
class Settings extends ShopSettings
{
    protected function registry(): string
    {
        return RentalSettings::class;
    }

    protected function pageTitle(): string
    {
        return 'Rental Settings';
    }
}
