<?php

namespace App\Livewire\Customer\Account;

use App\Models\SavedItem;
use App\Support\SiteText;
use Livewire\Component;

/** Account → Saved: everything the customer liked (♡), newest first. Owner 2026-11-07; tabs added in look Phase 2. */
class Saved extends Component
{
    /** Tab => the item_key prefixes it shows. Dishes are products, restaurants are stores. */
    public const TABS = [
        'services' => ['service'],
        'items' => ['product'],
        'shops' => ['store'],
    ];

    public string $tab = 'all';

    public function pickTab(string $tab): void
    {
        $this->tab = isset(self::TABS[$tab]) ? $tab : 'all';
    }

    public function remove(int $id): void
    {
        SavedItem::where('user_id', auth()->id())->whereKey($id)->delete();
    }

    public static function tabFor(string $itemKey): ?string
    {
        $type = strstr($itemKey, ':', true) ?: $itemKey;
        foreach (self::TABS as $tab => $types) {
            if (in_array($type, $types, true)) {
                return $tab;
            }
        }

        return null;
    }

    public function render()
    {
        $all = SavedItem::where('user_id', auth()->id())->latest('id')->get();
        $counts = $all->countBy(fn (SavedItem $i) => self::tabFor($i->item_key) ?? 'other');
        $tab = isset(self::TABS[$this->tab]) && ($counts[$this->tab] ?? 0) > 0 ? $this->tab : 'all';

        return view('livewire.customer.account.saved', [
            'items' => $tab === 'all' ? $all : $all->filter(fn (SavedItem $i) => self::tabFor($i->item_key) === $tab)->values(),
            'total' => $all->count(),
            'counts' => $counts,
            'activeTab' => $tab,
        ])->layout('components.layouts.customer', ['title' => SiteText::get('saved.title')]);
    }
}
