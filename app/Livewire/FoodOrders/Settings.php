<?php

namespace App\Livewire\FoodOrders;

use App\Livewire\MarketplaceOrders\Settings as ShopSettings;
use App\Support\FoodSettings;

/** Admin → Food Settings: the Shop Settings page (same scopes, validation, audit log, Super Admin only) over FoodSettings. */
class Settings extends ShopSettings
{
    protected function registry(): string
    {
        return FoodSettings::class;
    }

    protected function pageTitle(): string
    {
        return 'Food Settings';
    }
}
