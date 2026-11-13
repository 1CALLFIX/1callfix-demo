<?php

namespace App\Livewire\Customer\Events;

use App\Livewire\Customer\Events\Concerns\InteractsWithEventCity;
use App\Models\Event;
use App\Models\Setting;
use App\Services\Events\EventTicketService;
use App\Support\EventSettings;
use App\Support\SiteText;
use Livewire\Component;

/**
 * Events E2 — /{city}/events/{event}: cover (♡ Like + Share), title, when, where (Directions), age limit, organiser,
 * about, rules, and every ticket type with its price including GST, what is left and when its sale ends. Choosing how
 * many opens the buy page (signed in), which charges exactly the bill shown there. The page itself stays public and
 * cacheable.
 */
class Show extends Component
{
    use InteractsWithEventCity;

    public string $eventSlug = '';

    public function mount(string $city, string $eventSlug): void
    {
        if (! $this->enterEventCity($city)) {
            return;
        }
        $this->eventSlug = $eventSlug;
        abort_unless(self::publicEventsIn($this->eventCity)->where('slug', $eventSlug)->exists(), 404);
    }

    public function render()
    {
        $event = self::publicEventsIn($this->eventCity)->where('slug', $this->eventSlug)->with(['ticketTypes', 'franchise', 'organiser'])->firstOrFail();
        $tz = app(\App\Services\TimezoneResolver::class)->timezoneFor($event->franchise);
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $onSale = EventTicketService::onSale($event);
        $scope = EventTicketService::scope($event);

        $tickets = $event->ticketTypes->where('is_active', true)->map(function ($type) use ($symbol, $tz) {
            $gst = EventSettings::gstPercentFor((float) $type->price);
            $available = EventTicketService::typeOnSale($type);

            return [
                'type' => $type,
                'price' => $symbol.number_format((float) $type->price * (1 + (float) $gst / 100), 0),
                'available' => $available,
                'left' => $available && $type->left() <= 10 ? SiteText::get('event.show.left', ['count' => $type->left()]) : null,
                'ends' => $available && $type->sale_ends_at ? SiteText::get('event.show.sale_ends', ['time' => $type->sale_ends_at->copy()->setTimezone($tz)->format('j M, g:i A')]) : null,
                'admits' => $type->admits > 1 ? SiteText::get('event.show.admits', ['n' => $type->admits]) : null,
                'max' => min($type->max_per_order, $type->left()),
            ];
        })->values();

        return view('livewire.customer.events.show', [
            'event' => $event,
            'city' => $this->eventCity,
            'card' => Home::card($event, $this->eventCity->slug),
            'tickets' => $tickets,
            'onSale' => $onSale,
            'taxesKnown' => $tickets->every(fn ($t) => EventSettings::gstPercentFor((float) $t['type']->price) !== null),
            'when' => $event->starts_at->copy()->setTimezone($tz)->format('l, j F Y · g:i A').' – '.$event->ends_at->copy()->setTimezone($tz)->format($event->ends_at->copy()->setTimezone($tz)->isSameDay($event->starts_at->copy()->setTimezone($tz)) ? 'g:i A' : 'j M, g:i A'),
            'mapUrl' => $event->lat !== null ? 'https://www.google.com/maps/search/?api=1&query='.$event->lat.','.$event->lng
                : 'https://www.google.com/maps/search/?api=1&query='.urlencode($event->venue_name.', '.$event->venue_address),
            'refundLine' => \App\Services\Events\EventTicketService::refundLine($event),
            'maxOrder' => (int) EventSettings::get('event.max_tickets_per_order', $scope),
            'schema' => $this->schema($event),
        ])->layout('components.layouts.customer', [
            'title' => $event->title.' · '.$this->eventCity->name,
            'metaDescription' => \Illuminate\Support\Str::limit(trim($event->title.', '.$event->venue_name.'. '.$event->description), 155),
            'indexable' => true,
        ]);
    }

    /** schema.org Event for search engines (name, dates, place, image, ticket offers). */
    private function schema(Event $event): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $event->title,
            'description' => $event->description,
            'startDate' => $event->starts_at->toIso8601String(),
            'endDate' => $event->ends_at->toIso8601String(),
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'image' => $event->coverUrl() ? [$event->coverUrl()] : null,
            'location' => ['@type' => 'Place', 'name' => $event->venue_name, 'address' => ['@type' => 'PostalAddress', 'streetAddress' => $event->venue_address, 'addressLocality' => $this->eventCity->name, 'addressCountry' => 'IN']],
            'organizer' => ['@type' => 'Organization', 'name' => $event->organiser?->name],
            'offers' => $event->ticketTypes->where('is_active', true)->map(fn ($t) => ['@type' => 'Offer', 'name' => $t->name, 'price' => (float) $t->price, 'priceCurrency' => 'INR',
                'availability' => EventTicketService::typeOnSale($t) ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut'])->values()->all(),
        ]);
    }
}
