<?php

namespace App\Livewire\Customer\Hotels;

use App\Livewire\Customer\Hotels\Concerns\InteractsWithHotelCity;
use App\Models\Accommodation;
use App\Models\Review;
use App\Models\Setting;
use App\Support\HotelPricing;
use App\Support\HotelSettings;
use App\Support\SiteText;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Hotels H2 — /{city}/hotels/{hotel}: photos (♡ Like + Share on the first one), the hotel's own words, facilities,
 * check-in / out times, house rules, a Directions link, and every room for the chosen dates with its price a night
 * including taxes and the full breakdown (room price, GST, convenience fee, total) — the same HotelPricing quote the
 * booking charges. Book opens the booking page (H2b) for that room, plan and stay.
 */
class Show extends Component
{
    use InteractsWithHotelCity;

    public string $hotelSlug = '';

    #[Url(as: 'in')]
    public string $checkIn = '';

    #[Url(as: 'out')]
    public string $checkOut = '';

    #[Url(as: 'rooms')]
    public int $rooms = 1;

    #[Url(as: 'adults')]
    public int $adults = 2;

    #[Url(as: 'children')]
    public int $children = 0;

    public function mount(string $city, string $hotelSlug): void
    {
        if (! $this->enterHotelCity($city)) {
            return;
        }
        $this->hotelSlug = $hotelSlug;
        [$this->checkIn, $this->checkOut] = $this->stayDates($this->checkIn, $this->checkOut);
        abort_unless(self::publicHotelsIn($this->hotelCity)->where('slug', $hotelSlug)->exists(), 404);
    }

    public function render()
    {
        $hotel = self::publicHotelsIn($this->hotelCity)->where('slug', $this->hotelSlug)
            ->with(['accommodationType', 'franchise', 'roomTypes' => fn ($q) => $q->where('is_active', true)->orderBy('id'), 'roomTypes.ratePlans', 'roomTypes.accommodation'])
            ->firstOrFail();
        [$in, $out, $nights] = $this->stayDates($this->checkIn, $this->checkOut);
        $roomsWanted = max(1, min((int) HotelSettings::get('hotel.max_rooms_per_booking'), $this->rooms));
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $money = fn (float $v) => $symbol.number_format($v, 0);
        $url = fn ($p) => $p ? (str_starts_with((string) $p, 'http') ? $p : Storage::disk('public')->url($p)) : null;

        $roomCards = [];
        foreach ($hotel->roomTypes as $room) {
            foreach ($room->ratePlans->where('is_active', true)->where('nightly_rate', '>', 0)->sortBy('nightly_rate') as $plan) {
                $q = HotelPricing::quote($room, $plan, $in, $out, $roomsWanted);
                $fits = $room->max_adults * $roomsWanted >= $this->adults && (int) $room->max_children * $roomsWanted >= max(0, $this->children);
                $roomCards[] = [
                    'room' => $room, 'plan' => $plan, 'quote' => $q, 'fits' => $fits,
                    'photos' => array_values(array_filter(array_map($url, $room->photos ?: [$room->cover_image]))),
                    'meal' => SiteText::get('hotel.meal.'.$plan->meal_plan),
                    'perNight' => $money($q['per_night_with_tax']),
                    'lines' => [
                        [SiteText::get('hotel.show.room_price').' · '.SiteText::get('hotel.show.stay', ['rooms' => SiteText::count('hotel.rooms', $roomsWanted), 'nights' => SiteText::count('hotel.nights', $nights)]), $money($q['room_total'])],
                        ...($q['gst'] > 0 ? [[SiteText::get('hotel.show.gst'), $money($q['gst'])]] : []),
                        ...($q['fee'] > 0 ? [[SiteText::get('hotel.show.fee'), $money($q['fee'])]] : []),
                    ],
                    'total' => $money($q['total']),
                    'left' => $q['available'] && $q['left'] <= 3 ? SiteText::get('hotel.card.left', ['count' => $q['left']]) : null,
                    'bookable' => $q['available'] && $fits && $q['taxes_known'],
                ];
            }
        }

        $rating = Review::query()->join('hotel_reservations', 'hotel_reservations.id', '=', 'reviews.hotel_reservation_id')
            ->where('hotel_reservations.accommodation_id', $hotel->id)->selectRaw('avg(reviews.rating) as avg, count(*) as n')->first();
        $header = Home::card($hotel, $this->hotelCity->slug, HotelPricing::cheapest($hotel, $in, $out, $roomsWanted, $this->adults, max(0, $this->children)), $nights, $rating, ['in' => $in, 'out' => $out, 'rooms' => $roomsWanted, 'adults' => $this->adults, 'children' => max(0, $this->children)]);
        $photos = array_values(array_unique([...$hotel->photoUrls(), ...collect($roomCards)->pluck('photos')->flatten()->all()]));

        return view('livewire.customer.hotels.show', [
            'hotel' => $hotel,
            'city' => $this->hotelCity,
            'header' => $header,
            'photos' => $photos,
            'roomCards' => $roomCards,
            'in' => $in, 'out' => $out, 'nights' => $nights,
            'times' => SiteText::get('hotel.show.times', [
                'in' => \Illuminate\Support\Carbon::parse($hotel->check_in_time_start ?: '14:00')->format('g:i A'),
                'out' => \Illuminate\Support\Carbon::parse($hotel->check_out_time ?: '11:00')->format('g:i A'),
            ]),
            'mapUrl' => 'https://www.google.com/maps/search/?api=1&query='.$hotel->lat.','.$hotel->lng,
            'schema' => $this->schema($hotel, $photos, $header),
            'reviewSummary' => \App\Services\Hotels\HotelReviewService::summary($hotel),
            'reviews' => \App\Models\Review::whereHas('hotelReservation', fn ($q) => $q->where('accommodation_id', $hotel->id))
                ->with('customer:id,name')->latest()->limit(10)->get(),
        ])->layout('components.layouts.customer', [
            'title' => $hotel->name.' · '.$this->hotelCity->name,
            'metaDescription' => \Illuminate\Support\Str::limit(trim(($hotel->accommodationType?->name ?? '').' in '.$this->hotelCity->name.'. '.$hotel->description), 155),
            'indexable' => true,
        ]);
    }

    /** schema.org Hotel for search engines (name, address, photos, price range, rating when there are reviews). */
    private function schema(Accommodation $hotel, array $photos, array $header): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Hotel',
            'name' => $hotel->name,
            'description' => $hotel->description,
            'image' => array_slice($photos, 0, 5),
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => $hotel->address_line, 'addressLocality' => $this->hotelCity->name, 'addressCountry' => 'IN'],
            'geo' => ['@type' => 'GeoCoordinates', 'latitude' => (float) $hotel->lat, 'longitude' => (float) $hotel->lng],
            'checkinTime' => substr((string) $hotel->check_in_time_start, 0, 5) ?: null,
            'checkoutTime' => substr((string) $hotel->check_out_time, 0, 5) ?: null,
            'amenityFeature' => array_map(fn ($f) => ['@type' => 'LocationFeatureSpecification', 'name' => $f, 'value' => true], $hotel->facilities ?? []),
            'aggregateRating' => $header['rating'] ? ['@type' => 'AggregateRating', 'ratingValue' => $header['rating']['avg'], 'reviewCount' => $header['rating']['n']] : null,
        ]);
    }
}
