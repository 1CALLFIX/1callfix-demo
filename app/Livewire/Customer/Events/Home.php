<?php

namespace App\Livewire\Customer\Events;

use App\Livewire\Customer\Events\Concerns\InteractsWithEventCity;
use App\Models\Event;
use App\Models\Setting;
use App\Services\Events\EventTicketService;
use App\Support\SiteText;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Events E2 — /{city}/events: every approved event in the city that is not over yet, soonest first (featured first),
 * filterable by kind. Each card: cover with ♡ Like + Share, title, date and time, venue, lowest ticket price or Sold out.
 */
class Home extends Component
{
    use InteractsWithEventCity;

    #[Url(as: 'kind')]
    public string $kind = '';

    public function mount(string $city): void
    {
        $this->enterEventCity($city);
    }

    /** One event card's words, shared with the event page header. */
    public static function card(Event $event, string $citySlug): array
    {
        $tz = app(\App\Services\TimezoneResolver::class)->timezoneFor($event->franchise);
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $onSale = $event->ticketTypes->filter(fn ($t) => EventTicketService::typeOnSale($t));
        $from = $onSale->min('price');

        return [
            'url' => route('customer.events.show', [$citySlug, $event->slug]),
            'when' => $event->starts_at->copy()->setTimezone($tz)->format('D, j M · g:i A'),
            'from' => $from !== null ? SiteText::get('event.card.from', ['price' => $symbol.number_format((float) $from)]) : null,
            'soldOut' => $onSale->isEmpty(),
            'cover' => $event->coverUrl(),
        ];
    }

    public function render()
    {
        $events = self::publicEventsIn($this->eventCity)->with(['ticketTypes', 'franchise', 'organiser'])
            ->when($this->kind !== '', fn ($q) => $q->where('category', $this->kind))
            ->reorder()->orderByDesc('is_featured')->orderBy('starts_at')->limit(60)->get();
        $kinds = self::publicEventsIn($this->eventCity)->reorder()->distinct()->orderBy('category')->pluck('category')->filter()->values();
        $brand = Setting::get('branding.platform_name', '1CallFix');

        return view('livewire.customer.events.home', [
            'city' => $this->eventCity,
            'events' => $events,
            'cards' => $events->mapWithKeys(fn (Event $e) => [$e->id => self::card($e, $this->eventCity->slug)]),
            'kinds' => $kinds,
        ])->layout('components.layouts.customer', [
            'title' => SiteText::get('event.meta.title', ['city' => $this->eventCity->name]),
            'metaDescription' => SiteText::get('event.meta.description', ['city' => $this->eventCity->name, 'brand' => $brand]),
            'indexable' => true,
        ]);
    }
}
