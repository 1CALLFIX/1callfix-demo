<?php

namespace App\Livewire\Rider;

use App\Livewire\Partner\IdCardScreen;

/** Owner 2026-11-26: the rider's / driver's 1CallFix ID card (Rider app → More → My ID card). */
class IdCard extends IdCardScreen
{
    protected function type(): string
    {
        return 'rider';
    }

    protected function layout(): string
    {
        return 'components.layouts.rider';
    }
}
