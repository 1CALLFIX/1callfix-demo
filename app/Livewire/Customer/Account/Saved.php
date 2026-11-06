<?php

namespace App\Livewire\Customer\Account;

use App\Models\SavedItem;
use App\Support\SiteText;
use Livewire\Component;

/** Account → Saved: everything the customer liked (♡), newest first. Owner 2026-11-07. */
class Saved extends Component
{
    public function remove(int $id): void
    {
        SavedItem::where('user_id', auth()->id())->whereKey($id)->delete();
    }

    public function render()
    {
        return view('livewire.customer.account.saved', [
            'items' => SavedItem::where('user_id', auth()->id())->latest('id')->get(),
        ])->layout('components.layouts.customer', ['title' => SiteText::get('saved.title')]);
    }
}
