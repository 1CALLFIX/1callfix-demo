<?php

namespace App\Services\Marketing;

use App\Livewire\Customer\Events\Show as EventShow;
use App\Livewire\Customer\Food\RestaurantShow;
use App\Livewire\Customer\Halls\Show as HallShow;
use App\Livewire\Customer\Hotels\Show as HotelShow;
use App\Livewire\Customer\Shop\StoreShow;
use App\Models\Accommodation;
use App\Models\City;
use App\Models\Event;
use App\Models\Hall;
use App\Models\Provider;
use App\Models\Store;
use App\Models\User;
use App\Models\VehicleListing;
use App\Services\Vehicles\VehicleListingService;
use App\Support\Modules;

/**
 * "📣 Share my business" for partners (owner 2026-11-22: "every shop, hotel, user, businesses"): the partner's own
 * shops, restaurants, hotels, halls, events and vehicles that customers can open right now, each with a picture and
 * ready words, for one tap to their own WhatsApp / Facebook / Instagram. Only live pages (the same queries the public
 * pages use), so a shared link never opens a "not found" page. Links carry utm_source=partner for Live Visitors.
 */
class PartnerBusinesses
{
    /** @return list<array{kind: string, name: string, url: string, image: ?string, text: string}> */
    public static function for(?User $user): array
    {
        $providerIds = $user ? Provider::where('user_id', $user->id)->pluck('id') : collect();
        if ($providerIds->isEmpty()) {
            return [];
        }
        $cards = [];
        $add = function (string $kind, string $name, string $route, array $params, ?string $image, ?string $module, City $city) use (&$cards) {
            $url = route($route, $params).'?'.http_build_query(['utm_source' => 'partner', 'utm_medium' => 'social', 'utm_campaign' => 'share-my-business']);
            $image = $image ? url($image) : null;
            $cards[] = ['kind' => $kind, 'name' => $name, 'url' => $url, 'image' => $image,
                'text' => ItemPost::caption($name, $module, $city->name)."\n\n".$url];
        };

        foreach (Store::whereIn('provider_id', $providerIds)->with('franchise.city')->get() as $store) {
            $city = $store->franchise?->city;
            if (! $city) {
                continue;
            }
            if ($store->module === Modules::FOOD && RestaurantShow::publicRestaurantsIn($city)->whereKey($store->id)->exists() && ! $store->missingFssai()) {
                $add('Restaurant', $store->name, 'customer.food.restaurant', [$city->slug, $store->slug], $store->cover_url ?? $store->logo_url, 'food', $city);
            } elseif ($store->module === Modules::COMMERCE && StoreShow::publicStoresIn($city)->whereKey($store->id)->exists()) {
                $add('Shop', $store->name, 'customer.shop.store', [$city->slug, $store->slug], $store->cover_url ?? $store->logo_url, 'shop', $city);
            }
        }
        foreach (Accommodation::whereIn('provider_id', $providerIds)->with('franchise.city')->get() as $hotel) {
            if (($city = $hotel->franchise?->city) && HotelShow::publicHotelsIn($city)->whereKey($hotel->id)->exists()) {
                $add('Hotel', $hotel->name, 'customer.hotels.show', [$city->slug, $hotel->slug], $hotel->photoUrls()[0] ?? null, 'hotel', $city);
            }
        }
        foreach (Hall::whereIn('provider_id', $providerIds)->with('franchise.city')->get() as $hall) {
            if (($city = $hall->franchise?->city) && HallShow::publicHallsIn($city)->whereKey($hall->id)->exists()) {
                $add('Hall', $hall->name, 'customer.halls.show', [$city->slug, $hall->slug], $hall->coverUrl(), 'rental', $city);
            }
        }
        $events = Event::whereHas('organiser', fn ($q) => $q->whereIn('provider_id', $providerIds))->with('franchise.city')->latest('id')->limit(20)->get();
        foreach ($events as $event) {
            if (($city = $event->franchise?->city) && EventShow::publicEventsIn($city)->whereKey($event->id)->exists()) {
                $add('Event', $event->title, 'customer.events.show', [$city->slug, $event->slug], $event->coverUrl(), 'events', $city);
            }
        }
        foreach (VehicleListing::whereIn('provider_id', $providerIds)->with('franchise.city')->get() as $vehicle) {
            if (($city = $vehicle->franchise?->city) && VehicleListingService::publicIn($city)->whereKey($vehicle->id)->exists()) {
                $add('Vehicle', $vehicle->title, 'customer.vehicles.show', [$city->slug, $vehicle->slug], $vehicle->coverUrl(), 'rental', $city);
            }
        }

        return $cards;
    }
}
