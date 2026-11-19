<?php

namespace App\Livewire\Customer\Account;

use App\Models\RentalItem;
use App\Services\RentalItems\RentalItemService;
use App\Support\PostTypes;
use App\Support\RentalItemSettings;
use Livewire\Component;

/**
 * Account → Rent out your things (Rent anything, owner decision 2026-11-18): the person's posted items with a coloured
 * status badge each, one big "+ Rent out something" button, and one-tap "Not available now" / "Available again" /
 * "Renew" on approved items. Account → Second-hand (SecondHandItems, 2026-11-19) is the same page for used things
 * people sell, with "Mark as sold" instead of "Not available now".
 */
class RentItems extends Component
{
    /** Which kind of post this page lists (PostTypes). */
    protected string $deal = PostTypes::RENT;

    public string $notice = '';

    public function mount(): void
    {
        $this->notice = (string) session($this->noticeKey(), '');
    }

    /** Where the editor leaves its "Sent!" line for this page. */
    public static function noticeKeyFor(string $deal): string
    {
        return $deal === PostTypes::SELL ? 'secondhand-items.notice' : 'rent-items.notice';
    }

    private function noticeKey(): string
    {
        return self::noticeKeyFor($this->deal);
    }

    private function mine(int $id): RentalItem
    {
        return RentalItem::where('user_id', auth()->id())->where('deal', $this->deal)->findOrFail($id);
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

    /** Second-hand: sold. It leaves the lists; its page says "Sold". */
    public function markSold(int $id): void
    {
        $this->run(fn () => app(RentalItemService::class)->markSold(auth()->user(), $this->mine($id)), 'Marked sold. Well done! It no longer shows to customers.');
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
        $user = auth()->user();
        $sale = $this->deal === PostTypes::SELL;

        return view('livewire.customer.account.rent-items', [
            'items' => RentalItem::where('user_id', $user->id)->where('deal', $this->deal)->latest('id')->get(),
            'max' => (int) RentalItemSettings::get(PostTypes::key($this->deal, 'max_live_per_person')),
            'up' => RentalItemService::liveCount($user, null, $this->deal),
            'blocked' => RentalItemService::cannotPostReason($user, [], null, $this->deal),
            'open' => RentalItemService::postingZones($this->deal)->isNotEmpty(),
            'sale' => $sale,
            'createRoute' => PostTypes::route($this->deal, 'create'),
            'editRoute' => PostTypes::route($this->deal, 'edit'),
        ])->layout('components.layouts.customer', ['title' => $sale ? 'Your second-hand posts' : 'Rent out your things']);
    }
}
