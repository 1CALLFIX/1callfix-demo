<?php

namespace App\Livewire\Customer\Account;

use App\Support\PostTypes;

/** Account → Second-hand → post / change a used thing (owner 2026-11-19): the same four steps, one price, no deposit. */
class SecondHandEditor extends RentItemEditor
{
    protected string $deal = PostTypes::SELL;
}
