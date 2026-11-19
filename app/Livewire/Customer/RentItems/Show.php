<?php

namespace App\Livewire\Customer\RentItems;

use App\Livewire\Customer\RentItems\Concerns\InteractsWithItemCity;
use App\Models\RentalItem;
use App\Models\Setting;
use App\Services\RentalItems\RentalItemService;
use App\Support\PostTypes;
use Livewire\Component;

/**
 * Rent anything (owner decision 2026-11-18) — /{city}/rent/{item}: every photo (♡ Like + Share), price, deposit,
 * condition, kind, delivery, a few words, the area and landmark. The contact panel (owner's number, call, WhatsApp,
 * report) loads on its own request, so this page stays public and cacheable.
 *
 * Second-hand (SecondHand\Show, 2026-11-19) — /{city}/second-hand/{item}: one price ("Price can be discussed"), "Bill
 * available", and once sold the page stays up saying "Sold" (links never break, not indexed). A post opened under the
 * other kind's address 301s to its own one.
 */
class Show extends Component
{
    use InteractsWithItemCity;

    /** Which kind of post this page shows (PostTypes). */
    protected string $deal = PostTypes::RENT;

    public string $itemSlug = '';

    public function mount(string $city, string $itemSlug): void
    {
        if (! $this->enterItemCity($city)) {
            return;
        }
        $this->itemSlug = $itemSlug;
        if (RentalItemService::publicIn($this->itemCity, $this->deal, true)->where('slug', $itemSlug)->exists()) {
            return;
        }
        // The other kind of post with this slug (e.g. /rent/second-hand-sofa-...): its own address, permanently.
        $other = $this->deal === PostTypes::SELL ? PostTypes::RENT : PostTypes::SELL;
        $item = RentalItem::where('slug', $itemSlug)->where('deal', $other)->first();
        if ($item && \App\Support\Seo\CityModules::postTypeScopesIn($this->itemCity, $other)['live']
            && RentalItemService::publicIn($this->itemCity, $other, true)->whereKey($item->id)->exists()) {
            $query = request()->getQueryString();
            // A plain 301 response: inside Livewire, redirect() is Livewire's own redirector, not a response.
            throw new \Illuminate\Http\Exceptions\HttpResponseException(new \Illuminate\Http\RedirectResponse(PostTypes::url($item, $this->itemCity->slug).($query ? '?'.$query : ''), 301));
        }
        abort(404);
    }

    public function render()
    {
        $item = RentalItemService::publicIn($this->itemCity, $this->deal, true)->where('slug', $this->itemSlug)->with(['zone', 'franchise'])->firstOrFail();
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $money = fn ($v) => $v ? $symbol.number_format((float) $v) : null;
        $t = fn ($k, $r = []) => PostTypes::text($this->deal, $k, $r);
        $card = Home::card($item, $this->itemCity->slug);
        $sale = $item->isSale();
        $sold = $item->isSold();

        return view('livewire.customer.rent-items.show', [
            'item' => $item,
            'deal' => $this->deal,
            'city' => $this->itemCity,
            'card' => $card,
            'sold' => $sold,
            'photos' => array_values(array_filter(array_merge([$item->coverUrl()], $item->photoUrls()))),
            'facts' => array_values(array_filter([
                [$t('show.price'), $card['price'], 'bg-violet-50 text-violet-900'],
                ! $sale && $item->deposit ? [$t('show.deposit'), $money($item->deposit), 'bg-sky-50 text-sky-900'] : null,
                $item->category ? [$t('show.kind'), $item->category, 'bg-pink-50 text-pink-900'] : null,
                $item->condition ? [$t('show.condition'), $item->condition, 'bg-amber-50 text-amber-900'] : null,
            ])),
            'badges' => array_values(array_filter([
                $item->can_deliver ? [$t('show.deliver'), 'bg-emerald-100 text-emerald-800'] : null,
                $sale && $item->negotiable ? [$t('show.negotiable'), 'bg-violet-100 text-violet-800'] : null,
                $sale && $item->has_bill ? [$t('show.bill'), 'bg-sky-100 text-sky-800'] : null,
            ])),
            'schema' => array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => $item->title,
                'description' => $item->about,
                'image' => $item->coverUrl() ? [$item->coverUrl()] : null,
                'offers' => $item->price ? array_filter([
                    '@type' => 'Offer', 'price' => (string) (float) $item->price, 'priceCurrency' => 'INR',
                    'availability' => $sold ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
                    'itemCondition' => $sale ? 'https://schema.org/UsedCondition' : null,
                ]) : null,
            ]),
        ])->layout('components.layouts.customer', [
            'title' => ($sold ? $t('show.sold').': ' : '').$item->title.' · '.$this->itemCity->name,
            'metaDescription' => \Illuminate\Support\Str::limit(trim($item->title.'. '.$card['price'].'. '.$item->about), 155),
            'indexable' => ! $sold,
        ]);
    }
}
