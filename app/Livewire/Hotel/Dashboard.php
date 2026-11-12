<?php

namespace App\Livewire\Hotel;

use App\Livewire\Hotel\Concerns\InteractsWithHotel;
use App\Models\HotelRatePlan;
use App\Services\ModuleActivationService;
use App\Support\HotelSettings;
use App\Support\Modules;
use Livewire\Component;

/**
 * Hotel portal home (Hotels H1): where the hotel stands (waiting for approval / live / not approved) and a short
 * checklist of what it still needs before approval — details, map pin, photos, at least one room with a price.
 */
class Dashboard extends Component
{
    use InteractsWithHotel;

    /** @return list<array{label: string, done: bool, route: string}> */
    public static function checklist(\App\Models\Accommodation $hotel, array $scope): array
    {
        $minPhotos = (int) HotelSettings::get('hotel.min_photos', $scope);
        $pricedRooms = $hotel->roomTypes()->where('is_active', true)
            ->whereHas('ratePlans', fn ($q) => $q->where('is_active', true)->where('nightly_rate', '>', 0))->count();
        $zone = $hotel->zone;
        $pinned = $zone === null || (abs((float) $hotel->lat - (float) $zone->center_lat) > 0.00001 || abs((float) $hotel->lng - (float) $zone->center_lng) > 0.00001);

        return [
            ['label' => 'About your hotel (a few lines for guests)', 'done' => filled($hotel->description), 'route' => 'hotel.details'],
            ['label' => 'Exact location pinned on the map', 'done' => $pinned, 'route' => 'hotel.details'],
            ['label' => "At least {$minPhotos} photos", 'done' => $hotel->photoCount() >= $minPhotos, 'route' => 'hotel.details'],
            ['label' => 'At least one room with a price', 'done' => $pricedRooms > 0, 'route' => 'hotel.rooms'],
        ];
    }

    /** @var array<int, string> reply typed per review */
    public array $replies = [];

    public string $notice = '';

    public function reply(int $reviewId, \App\Services\Hotels\HotelReviewService $reviews): void
    {
        $this->validate(['replies.'.$reviewId => ['required', 'string', 'min:2', 'max:1000']], ['replies.*.required' => 'Write your reply first.']);
        $review = \App\Models\Review::findOrFail($reviewId);
        $reviews->reply($review, $this->hotel(), $this->replies[$reviewId]);
        unset($this->replies[$reviewId]);
        $this->notice = 'Reply posted. Guests see it under the review.';
    }

    public function render()
    {
        $hotel = $this->hotel();
        $scope = $this->hotelScope($hotel);
        $checklist = self::checklist($hotel, $scope);

        return view('livewire.hotel.dashboard', [
            'hotel' => $hotel,
            'checklist' => $checklist,
            'reviews' => \App\Models\Review::whereHas('hotelReservation', fn ($q) => $q->where('accommodation_id', $hotel->id))->with('customer:id,name')->latest()->limit(10)->get(),
            'reviewSummary' => \App\Services\Hotels\HotelReviewService::summary($hotel),
            'ready' => collect($checklist)->every('done'),
            'moduleLive' => app(ModuleActivationService::class)->isActive(Modules::HOTEL, $scope),
            'rooms' => $hotel->roomTypes()->where('is_active', true)->count(),
            'fromPrice' => HotelRatePlan::whereIn('hotel_room_type_id', $hotel->roomTypes()->where('is_active', true)->pluck('id'))
                ->where('is_active', true)->where('nightly_rate', '>', 0)->min('nightly_rate'),
        ])->layout('components.layouts.hotel', ['title' => 'Home']);
    }
}
