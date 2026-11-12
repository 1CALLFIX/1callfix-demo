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

    #[Url(as: 'sort')]
    public string $sort = 'featured';

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

    public function render()
    {
        [$in, $out, $nights] = $this->stayDates($this->checkIn, $this->checkOut);
        $maxRooms = (int) HotelSettings::get('hotel.max_rooms_per_booking');
        $rooms = max(1, min($maxRooms, $this->rooms));
        $adults = max(1, min(50, $this->adults));
        $allowed = HotelSettings::lines('hotel.amenities');
        $wanted = array_values(array_intersect($allowed, $this->facilities));

        $hotels = self::publicHotelsIn($this->hotelCity)
            ->with(['accommodationType', 'franchise', 'roomTypes.ratePlans', 'roomTypes.accommodation'])
            ->get();

        $ratings = Review::query()->join('hotel_reservations', 'hotel_reservations.id', '=', 'reviews.hotel_reservation_id')
            ->whereIn('hotel_reservations.accommodation_id', $hotels->pluck('id'))
            ->groupBy('hotel_reservations.accommodation_id')
            ->selectRaw('hotel_reservations.accommodation_id as hotel_id, avg(reviews.rating) as avg, count(*) as n')
            ->get()->keyBy('hotel_id');

        $cards = $hotels->map(function (Accommodation $h) use ($in, $out, $nights, $rooms, $adults, $ratings) {
            $best = HotelPricing::cheapest($h, $in, $out, $rooms, $adults);
            if ($best === null || $this->breakfast) {
                // With "breakfast" on, only plans that include it count.
                $best = $this->breakfast ? $this->cheapestWithBreakfast($h, $in, $out, $rooms, $adults) : $best;
            }

            return self::card($h, $this->hotelCity->slug, $best, $nights, $ratings->get($h->id), ['in' => $in, 'out' => $out, 'rooms' => $rooms, 'adults' => $adults]);
        })
            ->filter(fn ($c) => $wanted === [] || array_diff($wanted, $c['facilities']) === [])
            ->filter(fn ($c) => ! $this->breakfast || $c['available'])
            ->filter(fn ($c) => $this->maxPrice <= 0 || ($c['perNight'] !== null && $c['perNight'] <= $this->maxPrice))
            ->sortBy([
                fn ($a, $b) => $b['available'] <=> $a['available'],
                fn ($a, $b) => match ($this->sort) {
                    'price' => ($a['total'] ?? PHP_INT_MAX) <=> ($b['total'] ?? PHP_INT_MAX),
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
        ])->layout('components.layouts.customer', [
            'title' => SiteText::get('hotel.meta.title', ['city' => $this->hotelCity->name]),
            'metaDescription' => SiteText::get('hotel.meta.description', ['city' => $this->hotelCity->name, 'brand' => $brand]),
            'indexable' => $this->sort === 'featured' && $this->maxPrice === 0 && ! $this->breakfast && $wanted === [] && $cards->isNotEmpty(),
        ]);
    }

    private function cheapestWithBreakfast(Accommodation $h, string $in, string $out, int $rooms, int $adults): ?array
    {
        $best = null;
        foreach ($h->roomTypes as $room) {
            if (! $room->is_active || $room->max_adults * $rooms < $adults) {
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
            'quickBook' => $quote && $quote['taxes_known'] ? route('customer.hotels.book', ['hotel' => $h->id, 'room' => $best[0]->id, 'plan' => $best[1]->id, 'in' => $stay['in'], 'out' => $stay['out'], 'rooms' => $stay['rooms'], 'adults' => $stay['adults']]) : null,
            'quickRoom' => $best ? $best[0]->name : null,
            'url' => route('customer.hotels.show', [$citySlug, $h->slug] + array_filter(['in' => $stay['in'], 'out' => $stay['out'], 'rooms' => $stay['rooms'] > 1 ? $stay['rooms'] : null, 'adults' => $stay['adults'] !== 2 ? $stay['adults'] : null])),
        ];
    }
}
