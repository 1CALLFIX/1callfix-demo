<?php

namespace App\Livewire\Provider;

use App\Livewire\Partner\IdCardScreen;

/** Owner 2026-11-26: the service partner's 1CallFix ID card (Partner app → More → My ID card). */
class IdCard extends IdCardScreen
{
    protected function type(): string
    {
        return 'provider';
    }

    protected function layout(): string
    {
        return 'components.layouts.provider';
    }
}
