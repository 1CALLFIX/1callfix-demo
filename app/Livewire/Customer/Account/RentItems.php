<?php

namespace App\Livewire\Customer\Account;

use App\Models\RentalItem;
use App\Services\RentalItems\PostPackService;
use App\Services\RentalItems\RentalItemService;
use App\Services\WalletService;
use App\Support\PostTypes;
use App\Support\RentalItemSettings;
use Livewire\Component;

/**
 * Account → Rent out your things (Rent anything, owner decision 2026-11-18): the person's posted items with a coloured
 * status badge each, one big "+ Rent out something" button, and one-tap "Not available now" / "Available again" /
 * "Renew" on approved items. Account → Second-hand (SecondHandItems, 2026-11-19) is the same page for used things
 * people sell, with "Mark as sold" instead of "Not available now".
 *
 * Paid packs (owner 2026-11-19): a live post shows "🚀 Get more calls" when any pack is on in its area. It opens the
 * pack cards (Featured, Boost, two bulk packs); the person taps one, then pays online or from the wallet. Featured
 * posts left from a bulk pack are used with one tap, no payment.
 */
class RentItems extends Component
{
    /** Which kind of post this page lists (PostTypes). */
    protected string $deal = PostTypes::RENT;

    public string $notice = '';

    /** The post whose pack cards are open, and the pack tapped. */
    public ?int $packFor = null;

    public string $pack = '';

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

    public function openPacks(int $id): void
    {
        $this->mine($id);
        $this->packFor = $id;
        $this->pack = '';
        $this->resetErrorBag();
    }

    public function closePacks(): void
    {
        $this->reset('packFor', 'pack');
    }

    public function pickPack(string $pack): void
    {
        $this->pack = in_array($pack, PostPackService::PACKS, true) ? $pack : '';
    }

    /** Pay for the tapped pack: "online" (Razorpay) or "wallet". */
    public function payPack(string $method): void
    {
        if ($this->packFor === null || $this->pack === '' || ! in_array($method, ['online', 'wallet'], true)) {
            return;
        }
        $item = $this->mine($this->packFor);
        $packs = app(PostPackService::class);
        $this->resetErrorBag();
        try {
            if ($method === 'wallet') {
                $packs->payFromWallet(auth()->user(), $item, $this->pack);
                $this->notice = self::startedLine($item->fresh(), $this->pack);
                $this->closePacks();
            } else {
                $this->dispatch('razorpay-open', order: $packs->startOnline(auth()->user(), $item, $this->pack))->self();
            }
        } catch (\RuntimeException $e) {
            $this->addError('packs', $e->getMessage());
        }
    }

    /** Feature this post with one Featured left from a bulk pack. */
    public function useCredit(int $id): void
    {
        $this->resetErrorBag();
        try {
            app(PostPackService::class)->useCredit(auth()->user(), $this->mine($id));
            $this->notice = self::startedLine($this->mine($id), PostPackService::FEATURED);
            $this->closePacks();
        } catch (\RuntimeException $e) {
            $this->addError('packs', $e->getMessage());
        }
    }

    private static function startedLine(RentalItem $item, string $pack): string
    {
        return $pack === PostPackService::BOOST
            ? 'Boosted! Your post is in the Boosted row until '.$item->boosted_until?->format('j M').'.'
            : 'Featured! Your post shows first with a Featured tag until '.$item->featured_until?->format('j M').'.';
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
            'packsOn' => fn (RentalItem $i) => $i->isLive() && PostPackService::anyOn($i),
            'packItem' => $packItem = $this->packFor ? RentalItem::where('user_id', $user->id)->find($this->packFor) : null,
            'offer' => $packItem ? PostPackService::offer($packItem) : [],
            'credits' => PostPackService::creditsLeft($user),
            'creditsUntil' => PostPackService::creditsUntil($user),
            'wallet' => $packItem ? app(WalletService::class)->balance($user) : 0,
            'online' => $packItem ? app(PostPackService::class)->onlineAvailable($packItem) : false,
        ])->layout('components.layouts.customer', ['title' => $sale ? 'Your second-hand posts' : 'Rent out your things']);
    }
}
