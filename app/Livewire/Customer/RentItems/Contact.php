<?php

namespace App\Livewire\Customer\RentItems;

use App\Models\RentalItem;
use App\Models\RentalItemContactView;
use App\Models\RentalItemReport;
use App\Services\RentalItems\RentalItemService;
use App\Support\PhoneNumber;
use App\Support\RentalItemSettings;
use App\Support\SiteText;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Rent anything — the item page's contact panel, loaded on its own request (the page itself is cached): the price, one
 * big "Show owner's number" button, then Call and WhatsApp. Below it, "Report this item" with tap-to-pick reasons.
 * No booking or payment runs here (OLX-style). Signed out: one button to sign in and come back.
 */
#[Lazy]
class Contact extends Component
{
    #[Locked]
    public int $itemId = 0;

    public ?string $ownerPhone = null;
    public string $error = '';
    public bool $reporting = false;
    public string $reason = '';
    public string $note = '';
    public string $reported = '';

    public function mount(int $itemId): void
    {
        $this->itemId = $itemId;
        if (($user = auth()->user()) && RentalItemContactView::where('customer_id', $user->id)->where('rental_item_id', $itemId)->exists()) {
            $item = RentalItem::with('user')->find($itemId);
            if ($item && RentalItemService::visible($item) && RentalItemSettings::on('items.show_owner_phone', $item->scope())) {
                $this->ownerPhone = (string) $item->user?->phone ?: null;
            }
        }
    }

    private function item(): RentalItem
    {
        $item = RentalItem::with(['franchise.city', 'user'])->find($this->itemId);
        abort_unless($item && RentalItemService::visible($item), 404);

        return $item;
    }

    public function placeholder()
    {
        return '<div class="h-48 animate-pulse rounded-2xl bg-slate-100" aria-hidden="true"></div>';
    }

    public function showPhone(RentalItemService $items): void
    {
        $this->reset('error');
        abort_unless(auth()->check(), 403);
        try {
            $this->ownerPhone = $items->revealPhone(auth()->user(), $this->item());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function sendReport(RentalItemService $items): void
    {
        $this->reset('error');
        abort_unless(auth()->check(), 403);
        $this->validate(['reason' => ['required', 'in:'.implode(',', array_keys(RentalItemReport::REASONS))], 'note' => ['nullable', 'string', 'max:500']],
            ['reason.required' => 'Tap what is wrong.', 'reason.in' => 'Tap what is wrong.']);
        try {
            $items->report(auth()->user(), $this->item(), $this->reason, $this->note);
            $this->reported = SiteText::get('items.report.thanks');
            $this->reset('reporting', 'reason', 'note');
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $item = $this->item();
        $user = auth()->user();
        $digits = $this->ownerPhone ? preg_replace('/\D/', '', PhoneNumber::e164($this->ownerPhone)) : null;
        $path = '/'.ltrim((string) parse_url(route('customer.rent.show', [(string) $item->franchise?->city?->slug, $item->slug]), PHP_URL_PATH), '/');

        return view('livewire.customer.rent-items.contact', [
            'item' => $item,
            'price' => Home::card($item, (string) $item->franchise?->city?->slug)['price'],
            'deposit' => $item->deposit ? '₹'.number_format((float) $item->deposit) : null,
            'phoneAllowed' => RentalItemSettings::on('items.show_owner_phone', $item->scope()),
            'own' => $user && $item->user_id === $user->id,
            'whatsapp' => $digits ? 'https://wa.me/'.$digits.'?text='.rawurlencode(SiteText::get('items.contact.whatsapp_text', ['title' => $item->title])) : null,
            'signInUrl' => route('customer.login', ['intended' => $path]),
            'reasons' => RentalItemReport::REASONS,
            'alreadyReported' => $user && RentalItemReport::where('rental_item_id', $item->id)->where('user_id', $user->id)->exists(),
        ]);
    }
}
