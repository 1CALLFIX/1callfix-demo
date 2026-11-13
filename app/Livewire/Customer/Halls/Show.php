<?php

namespace App\Livewire\Customer\Halls;

use App\Livewire\Customer\Halls\Concerns\InteractsWithHallCity;
use App\Models\Hall;
use App\Models\Setting;
use App\Services\Halls\HallBookingService;
use App\Support\HallSettings;
use App\Support\SiteText;
use Livewire\Component;

/**
 * Function halls R2 — /{city}/halls/{hall}: photos (♡ Like + Share), kind, seats / standing, AC, parking, rooms, where
 * (Directions), about, facilities, rules, deposit, and every slot with its time and price (GST included when 1CallFix
 * collects it). Choosing a date and a slot opens the booking page (signed in), which checks the date and charges the
 * bill shown there. The page itself stays public and cacheable.
 */
class Show extends Component
{
    use InteractsWithHallCity;

    public string $hallSlug = '';

    public function mount(string $city, string $hallSlug): void
    {
        if (! $this->enterHallCity($city)) {
            return;
        }
        $this->hallSlug = $hallSlug;
        abort_unless(self::publicHallsIn($this->hallCity)->where('slug', $hallSlug)->exists(), 404);
    }

    public function render()
    {
        $hall = self::publicHallsIn($this->hallCity)->where('slug', $this->hallSlug)->with(['slots', 'franchise'])->firstOrFail();
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $collect = HallSettings::on('hall.platform_collects_gst', HallBookingService::scope($hall));
        $slots = $hall->slots->where('is_active', true)->values();
        [$first, $last] = HallBookingService::dateWindow($hall);

        return view('livewire.customer.halls.show', [
            'hall' => $hall,
            'city' => $this->hallCity,
            'card' => Home::card($hall, $this->hallCity->slug),
            'photos' => array_values(array_filter(array_merge([$hall->coverUrl()], $hall->photoUrls()))),
            'hallSlots' => $slots->map(fn ($s) => [
                'slot' => $s,
                'price' => $symbol.number_format((float) $s->price + ($collect ? round((float) $s->price * (float) HallSettings::gstPercentFor((float) $s->price) / 100) : 0)),
            ]),
            'deposit' => $hall->deposit_amount ? $symbol.number_format((float) $hall->deposit_amount) : null,
            'first' => $first,
            'last' => $last,
            'taxesKnown' => ! $collect || $slots->every(fn ($s) => HallSettings::gstPercentFor((float) $s->price) !== null),
            'mapUrl' => $hall->lat !== null ? 'https://www.google.com/maps/search/?api=1&query='.$hall->lat.','.$hall->lng
                : 'https://www.google.com/maps/search/?api=1&query='.urlencode($hall->name.', '.$hall->address_line),
            'refundLine' => HallBookingService::refundLine($hall),
            'advance' => (int) HallSettings::get('hall.advance_percent', HallBookingService::scope($hall)),
            'schema' => $this->schema($hall),
        ])->layout('components.layouts.customer', [
            'title' => $hall->name.' · '.$this->hallCity->name,
            'metaDescription' => \Illuminate\Support\Str::limit(trim($hall->name.', '.$hall->hall_type.', '.$hall->seating_capacity.' seats. '.$hall->about), 155),
            'indexable' => true,
        ]);
    }

    /** schema.org EventVenue for search engines. */
    private function schema(Hall $hall): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'EventVenue',
            'name' => $hall->name,
            'description' => $hall->about,
            'image' => $hall->coverUrl() ? [$hall->coverUrl()] : null,
            'maximumAttendeeCapacity' => $hall->floating_capacity ?: $hall->seating_capacity,
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => $hall->address_line, 'addressLocality' => $this->hallCity->name, 'addressCountry' => 'IN'],
            'geo' => $hall->lat !== null ? ['@type' => 'GeoCoordinates', 'latitude' => (float) $hall->lat, 'longitude' => (float) $hall->lng] : null,
            'amenityFeature' => collect((array) $hall->amenities)->map(fn ($a) => ['@type' => 'LocationFeatureSpecification', 'name' => $a, 'value' => true])->values()->all() ?: null,
        ]);
    }
}
