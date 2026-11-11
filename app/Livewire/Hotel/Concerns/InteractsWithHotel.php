<?php

namespace App\Livewire\Hotel\Concerns;

use App\Models\Accommodation;
use App\Models\HotelRoomType;
use App\Models\Provider;

/** The signed-in hotel partner's own hotel and rooms — never another hotel's (404). */
trait InteractsWithHotel
{
    protected function partner(): Provider
    {
        return Provider::with('user')->where('user_id', auth()->id())->firstOrFail();
    }

    protected function hotel(): Accommodation
    {
        return Accommodation::with(['accommodationType', 'franchise'])
            ->where('provider_id', $this->partner()->id)->orderBy('id')->firstOrFail();
    }

    protected function ownRoom(int $roomTypeId): HotelRoomType
    {
        return HotelRoomType::where('accommodation_id', $this->hotel()->id)->findOrFail($roomTypeId);
    }

    /** Settings scope for this hotel (zone → franchise → city → country). */
    protected function hotelScope(Accommodation $hotel): array
    {
        return array_filter([
            'zone_id' => $hotel->zone_id, 'franchise_id' => $hotel->franchise_id,
            'city_id' => $hotel->franchise?->city_id, 'country_id' => $hotel->franchise?->country_id,
        ]);
    }
}
