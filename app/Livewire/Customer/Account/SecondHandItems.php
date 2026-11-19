<?php

namespace App\Livewire\Customer\Account;

use App\Support\PostTypes;

/** Account → Second-hand (owner 2026-11-19): your used things posted for sale, with "Mark as sold". */
class SecondHandItems extends RentItems
{
    protected string $deal = PostTypes::SELL;
}
