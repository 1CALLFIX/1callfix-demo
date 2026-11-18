<?php

namespace App\Livewire\Customer\RentItems;

use App\Livewire\Customer\RentItems\Concerns\InteractsWithItemCity;
use App\Models\Setting;
use App\Services\RentalItems\RentalItemService;
use App\Support\SiteText;
use Livewire\Component;

/**
 * Rent anything (owner decision 2026-11-18) — /{city}/rent/{item}: every photo (♡ Like + Share), price, deposit,
 * condition, kind, delivery, a few words, the area and landmark. The contact panel (owner's number, call, WhatsApp,
 * report) loads on its own request, so this page stays public and cacheable.
 */
class Show extends Component
{
    use InteractsWithItemCity;

    public string $itemSlug = '';

    public function mount(string $city, string $itemSlug): void
    {
        if (! $this->enterItemCity($city)) {
            return;
        }
        $this->itemSlug = $itemSlug;
        abort_unless(RentalItemService::publicIn($this->itemCity)->where('slug', $itemSlug)->exists(), 404);
    }

    public function render()
    {
        $item = RentalItemService::publicIn($this->itemCity)->where('slug', $this->itemSlug)->with(['zone', 'franchise'])->firstOrFail();
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $money = fn ($v) => $v ? $symbol.number_format((float) $v) : null;
        $t = fn ($k, $r = []) => SiteText::get($k, $r);
        $card = Home::card($item, $this->itemCity->slug);

        return view('livewire.customer.rent-items.show', [
            'item' => $item,
            'city' => $this->itemCity,
            'card' => $card,
            'photos' => array_values(array_filter(array_merge([$item->coverUrl()], $item->photoUrls()))),
            'facts' => array_values(array_filter([
                [$t('items.show.price'), $card['price'], 'bg-violet-50 text-violet-900'],
                $item->deposit ? [$t('items.show.deposit'), $money($item->deposit), 'bg-sky-50 text-sky-900'] : null,
                $item->category ? [$t('items.show.kind'), $item->category, 'bg-pink-50 text-pink-900'] : null,
                $item->condition ? [$t('items.show.condition'), $item->condition, 'bg-amber-50 text-amber-900'] : null,
            ])),
            'schema' => array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => $item->title,
                'description' => $item->about,
                'image' => $item->coverUrl() ? [$item->coverUrl()] : null,
                'offers' => $item->price ? ['@type' => 'Offer', 'price' => (string) (float) $item->price, 'priceCurrency' => 'INR', 'availability' => 'https://schema.org/InStock'] : null,
            ]),
        ])->layout('components.layouts.customer', [
            'title' => $item->title.' · '.$this->itemCity->name,
            'metaDescription' => \Illuminate\Support\Str::limit(trim($item->title.'. '.$card['price'].'. '.$item->about), 155),
            'indexable' => true,
        ]);
    }
}
