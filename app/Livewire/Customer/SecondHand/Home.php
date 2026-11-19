<?php

namespace App\Livewire\Customer\SecondHand;

use App\Support\PostTypes;

/**
 * Second-hand (owner 2026-11-19) — /{city}/second-hand: used things people near you sell, zone by zone where
 * Second-hand is on. The Rent anything page in Second-hand words: kinds, area, can deliver, cheapest first.
 */
class Home extends \App\Livewire\Customer\RentItems\Home
{
    protected string $deal = PostTypes::SELL;
}
