<?php

namespace App\Livewire\Customer\SecondHand;

use App\Support\PostTypes;

/** Second-hand (owner 2026-11-19) — /{city}/second-hand/{item}: one used thing; stays up saying "Sold" once sold. */
class Show extends \App\Livewire\Customer\RentItems\Show
{
    protected string $deal = PostTypes::SELL;
}
