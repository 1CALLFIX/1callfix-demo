<?php

namespace App\Livewire\Customer\Account;

use App\Models\RentalItem;
use App\Services\RentalItems\RentalItemService;
use App\Support\RentalItemSettings;
use Livewire\Component;

/**
 * Account → Rent out your things (Rent anything, owner decision 2026-11-18): the person's posted items with a coloured
 * status badge each, one big "+ Rent out something" button, and one-tap "Not available now" / "Available again" /
 * "Renew" on approved items.
 */
class RentItems extends Component
{
    public string $notice = '';

    public function mount(): void
    {
        $this->notice = (string) session('rent-items.notice', '');
    }

    private function mine(int $id): RentalItem
    {
        return RentalItem::where('user_id', auth()->id())->findOrFail($id);
    }

    public function notAvailable(int $id): void
    {
        $this->run(fn () => app(RentalItemService::class)->setAvailable(auth()->user(), $this->mine($id), false), 'Hidden. Tap "Available again" when it is free.');
    }

    public function availableAgain(int $id): void
    {
        $this->run(fn () => app(RentalItemService::class)->setAvailable(auth()->user(), $this->mine($id), true), 'Shown again.');
    }

    public function renew(int $id): void
    {
        $this->run(fn () => app(RentalItemService::class)->renew(auth()->user(), $this->mine($id)), 'Renewed. It shows for another '.RentalItemSettings::get('items.listing_days').' days.');
    }

    private function run(callable $action, string $done): void
    {
        $this->resetErrorBag();
        try {
            $action();
            $this->notice = $done;
        } catch (\RuntimeException $e) {
            $this->addError('items', $e->getMessage());
        }
    }

    public function render()
    {
        $items = RentalItem::where('user_id', auth()->id())->latest('id')->get();
        $max = (int) RentalItemSettings::get('items.max_live_per_person');

        return view('livewire.customer.account.rent-items', [
            'items' => $items,
            'max' => $max,
            'up' => RentalItemService::liveCount(auth()->user()),
            'blocked' => RentalItemService::cannotPostReason(auth()->user()),
            'open' => RentalItemService::postingZones()->isNotEmpty(),
        ])->layout('components.layouts.customer', ['title' => 'Rent out your things']);
    }
}
