<?php

namespace App\Livewire\Events;

use App\Livewire\MarketplaceOrders\Settings as ShopSettings;
use App\Support\EventSettings;

/** Admin → Event Settings: the Shop Settings page (same scopes, validation, audit log, Super Admin only) over EventSettings. */
class Settings extends ShopSettings
{
    protected function registry(): string
    {
        return EventSettings::class;
    }

    protected function pageTitle(): string
    {
        return 'Event Settings';
    }
}
