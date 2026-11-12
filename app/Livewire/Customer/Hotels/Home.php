<?php

namespace App\Livewire\Customer\Hotels;

use App\Livewire\Customer\Hotels\Concerns\InteractsWithHotelCity;
use App\Models\Accommodation;
use App\Models\Review;
use App\Models\Setting;
use App\Support\HotelPricing;
use App\Support\HotelSettings;
use App\Support\SiteText;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Hotels H2 — /{city}/hotels: the city's hotels for the chosen dates, rooms and adults. Every card shows the price a
 * night with taxes included and the whole-stay total, from HotelPricing (the same number paid at checkout), free
 * cancellation until when, and how many rooms are left when it is only a few. Sort: recommended (featured first,
 * then rating), lowest price, top rated. Filters: price a night, breakfast included, facilities (Hotel Settings list).
 * Hotels with no free room on the dates go to the bottom as "Full on these dates". Every word is SiteText.
 */
class Home extends Component
{
    use InteractsWithHotelCity;

    #[Url(as: 'in')]
    public string $checkIn = '';

    #[Url(as: 'out')]
    public string $checkOut = '';

    #[Url(as: 'rooms')]
    public int $rooms = 1;

    #[Url(as: 'adults')]
    public int $adults = 2;

    /** Hotels step 2: children travel too; a room counts them against its own children limit. */
    #[Url(as: 'children')]
    public int $children = 0;

    #[Url(as: 'sort')]
    public string $sort = 'featured';

    /** Step 3: area, landmark or hotel name (matched on the name, address, type and description). */
    #[Url(as: 'q')]
    public string $q = '';

    /** Step 3: "Near me" — "lat,lng" from the browser; cards then show the distance and the nearest come first. */
    #[Url(as: 'near')]
    public string $near = '';

    #[Url(as: 'map')]
    public bool $showMap = false;

    #[Url(as: 'max')]
    public int $maxPrice = 0;

    #[Url(as: 'breakfast')]
    public bool $breakfast = false;

    #[Url(as: 'has')]
    public array $facilities = [];

    public const PRICE_STEPS = [1000, 2000, 3000, 5000];

    public function mount(string $city): void
    {
        if ($this->enterHotelCity($city)) {
            // Show the dates the prices are for (tonight → tomorrow when the link has none).
            [$this->checkIn, $this->checkOut] = $this->stayDates($this->checkIn, $this->checkOut);
        }
    }

    /** "Near me" turned on: nearest first (the customer can still pick another order). */
    public function updatedNear(): void
    {
        if (self::point($this->near) !== null && $this->sort === 'featured') {
            $this->sort = 'distance';
        }
        if (self::point($this->near) === null && $this->sort === 'distance') {
            $this->sort = 'featured';
        }
    }

    public function render()
    {
        [$in, $out, $nights] = $this->stayDates($this->checkIn, $this->checkOut);
        $maxRooms = (int) HotelSettings::get('hotel.max_rooms_per_booking');
        $rooms = max(1, min($maxRooms, $this->rooms));
        $adults = max(1, min(50, $this->adults));
        $children = max(0, min(20, $this->children));
        $allowed = HotelSettings::lines('hotel.amenities');
        $wanted = array_values(array_intersect($allowed, $this->facilities));

        $hotels = self::publicHotelsIn($this->hotelCity)
            ->with(['accommodationType', 'franchise', 'roomTypes.ratePlans', 'roomTypes.accommodation'])
            ->get();
        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim(mb_substr($this->q, 0, 80)))));
        if ($words !== []) {
            $hotels = $hotels->filter(function (Accommodation $h) use ($words) {
                $hay = mb_strtolower(implode(' ', [$h->name, $h->address_line, $h->accommodationType?->name, $h->description]));

                return collect($words)->every(fn ($w) => str_contains($hay, $w));
            })->values();
        }
        $here = self::point($this->near);

        $ratings = Review::query()->join('hotel_reservations', 'hotel_reservations.id', '=', 'reviews.hotel_reservation_id')
            ->whereIn('hotel_reservations.accommodation_id', $hotels->pluck('id'))
            ->groupBy('hotel_reservations.accommodation_id')
            ->selectRaw('hotel_reservations.accommodation_id as hotel_id, avg(reviews.rating) as avg, count(*) as n')
            ->get()->keyBy('hotel_id');

        $cards = $hotels->map(function (Accommodation $h) use ($in, $out, $nights, $rooms, $adults, $children, $ratings, $here) {
            $best = HotelPricing::cheapest($h, $in, $out, $rooms, $adults, $children);
            if ($best === null || $this->breakfast) {
                // With "breakfast" on, only plans that include it count.
                $best = $this->breakfast ? $this->cheapestWithBreakfast($h, $in, $out, $rooms, $adults, $children) : $best;
            }

            $card = self::card($h, $this->hotelCity->slug, $best, $nights, $ratings->get($h->id), ['in' => $in, 'out' => $out, 'rooms' => $rooms, 'adults' => $adults, 'children' => $children]);
            $card['km'] = $here && $h->lat !== null && $h->lng !== null ? self::km($here, [(float) $h->lat, (float) $h->lng]) : null;
            $card['distance'] = $card['km'] !== null ? SiteText::get('hotel.card.distance', ['km' => number_format($card['km'], $card['km'] < 10 ? 1 : 0)]) : null;

            return $card;
        })
            ->filter(fn ($c) => $wanted === [] || array_diff($wanted, $c['facilities']) === [])
            ->filter(fn ($c) => ! $this->breakfast || $c['available'])
            ->filter(fn ($c) => $this->maxPrice <= 0 || ($c['perNight'] !== null && $c['perNight'] <= $this->maxPrice))
            ->sortBy([
                fn ($a, $b) => $b['available'] <=> $a['available'],
                fn ($a, $b) => match ($this->sort) {
                    'price' => ($a['total'] ?? PHP_INT_MAX) <=> ($b['total'] ?? PHP_INT_MAX),
                    'distance' => ($a['km'] ?? PHP_INT_MAX) <=> ($b['km'] ?? PHP_INT_MAX),
                    'rating' => ($b['rating']['avg'] ?? 0) <=> ($a['rating']['avg'] ?? 0),
                    default => [$b['featured'], $b['rating']['avg'] ?? 0] <=> [$a['featured'], $a['rating']['avg'] ?? 0],
                },
            ])->values();

        $brand = Setting::get('branding.platform_name', '1CallFix');

        return view('livewire.customer.hotels.home', [
            'cards' => $cards,
            'city' => $this->hotelCity,
            'in' => $in, 'out' => $out, 'nights' => $nights,
            'maxRooms' => $maxRooms,
            'allFacilities' => $allowed,
            'priceSteps' => self::PRICE_STEPS,
            'hasNear' => $here !== null,
            'mapKey' => config('services.google_maps.key'),
            'mapPoints' => $cards->filter(fn ($c) => $c['hotel']->lat !== null && $c['hotel']->lng !== null)->map(fn ($c) => [
                'lat' => (float) $c['hotel']->lat, 'lng' => (float) $c['hotel']->lng, 'name' => $c['hotel']->name,
                'price' => $c['available'] ? $c['perNightText'] : null, 'url' => $c['url'],
            ])->values()->all(),
            'here' => $here,
        ])->layout('components.layouts.customer', [
            'title' => SiteText::get('hotel.meta.title', ['city' => $this->hotelCity->name]),
            'metaDescription' => SiteText::get('hotel.meta.description', ['city' => $this->hotelCity->name, 'brand' => $brand]),
            'indexable' => $this->sort === 'featured' && $this->maxPrice === 0 && ! $this->breakfast && $wanted === [] && trim($this->q) === '' && $this->near === '' && $cards->isNotEmpty(),
        ]);
    }

    /** "lat,lng" → [lat, lng], or null for anything that is not a real point. */
    public static function point(string $near): ?array
    {
        if (! preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $near, $m)) {
            return null;
        }
        [$lat, $lng] = [(float) $m[1], (float) $m[2]];

        return abs($lat) <= 90 && abs($lng) <= 180 ? [$lat, $lng] : null;
    }

    /** Straight-line distance in km (haversine). */
    public static function km(array $a, array $b): float
    {
        [$la1, $lo1, $la2, $lo2] = array_map('deg2rad', [$a[0], $a[1], $b[0], $b[1]]);
        $h = sin(($la2 - $la1) / 2) ** 2 + cos($la1) * cos($la2) * sin(($lo2 - $lo1) / 2) ** 2;

        return round(6371 * 2 * asin(min(1, sqrt($h))), 1);
    }

    private function cheapestWithBreakfast(Accommodation $h, string $in, string $out, int $rooms, int $adults, int $children = 0): ?array
    {
        $best = null;
        foreach ($h->roomTypes as $room) {
            if (! $room->is_active || $room->max_adults * $rooms < $adults || (int) $room->max_children * $rooms < $children) {
                continue;
            }
            foreach ($room->ratePlans->where('is_active', true)->where('meal_plan', '!=', 'room_only') as $plan) {
                $q = HotelPricing::quote($room, $plan, $in, $out, $rooms);
                if ($q['available'] && ($best === null || $q['total'] < $best[2]['total'])) {
                    $best = [$room, $plan, $q];
                }
            }
        }

        return $best;
    }

    /** What a hotel card shows (also the hotel page header). */
    public static function card(Accommodation $h, string $citySlug, ?array $best, int $nights, $rating, array $stay): array
    {
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $money = fn (float $v) => $symbol.number_format($v, 0);
        $quote = $best[2] ?? null;
        $until = HotelPricing::freeCancelUntil($h, $stay['in']);
        $tzName = app(\App\Services\TimezoneResolver::class)->timezoneFor($h->franchise);

        return [
            'hotel' => $h,
            'type' => $h->accommodationType?->name,
            'photo' => $h->cover_image_url ?? ($h->roomTypes->firstWhere('cover_image')?->cover_image ? \Illuminate\Support\Facades\Storage::disk('public')->url($h->roomTypes->firstWhere('cover_image')->cover_image) : null),
            'facilities' => array_values($h->facilities ?? []),
            'featured' => (bool) $h->is_featured,
            'available' => $quote !== null,
            'perNight' => $quote['per_night_with_tax'] ?? null,
            'perNightText' => $quote ? $money($quote['per_night_with_tax']) : null,
            'priceNote' => $quote && $quote['taxes_known'] ? SiteText::get('hotel.card.per_night') : SiteText::get('hotel.card.plus_taxes'),
            'total' => $quote['total'] ?? null,
            'totalText' => $quote ? SiteText::get('hotel.card.total', ['amount' => $money($quote['total']), 'nights' => SiteText::count('hotel.nights', $nights)]) : null,
            'left' => $quote && $quote['left'] <= 3 ? SiteText::get('hotel.card.left', ['count' => $quote['left']]) : null,
            'cancel' => $until === null ? SiteText::get('hotel.free_cancel_always')
                : ($until->isFuture() ? SiteText::get('hotel.free_cancel_until', ['time' => $until->setTimezone($tzName)->format('j M, g:i A')]) : null),
            'rating' => $rating && $rating->n > 0 ? ['avg' => round((float) $rating->avg, 1), 'n' => (int) $rating->n] : null,
            // Fastest booking (owner 2026-11-12: "just a few taps"): the cheapest free room straight to the booking page.
            'quickBook' => $quote && $quote['taxes_known'] ? route('customer.hotels.book', ['hotel' => $h->id, 'room' => $best[0]->id, 'plan' => $best[1]->id, 'in' => $stay['in'], 'out' => $stay['out'], 'rooms' => $stay['rooms'], 'adults' => $stay['adults'], 'children' => ($stay['children'] ?? 0) ?: null]) : null,
            'quickRoom' => $best ? $best[0]->name : null,
            'url' => route('customer.hotels.show', [$citySlug, $h->slug] + array_filter(['in' => $stay['in'], 'out' => $stay['out'], 'rooms' => $stay['rooms'] > 1 ? $stay['rooms'] : null, 'adults' => $stay['adults'] !== 2 ? $stay['adults'] : null, 'children' => ($stay['children'] ?? 0) ?: null])),
        ];
    }
}
