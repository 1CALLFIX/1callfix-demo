<?php

namespace App\Livewire\Customer;

use App\Models\Zone;
use App\Services\Customer\CustomerLocationContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Where do you need service?" — the customer web app's zone/franchise
 * context selector (Phase B).
 *
 * Everything meaningful about this component is in
 * App\Services\Customer\CustomerLocationContext, including the deliberate
 * decision NOT to invent point-in-polygon geo-boundary resolution (see that
 * class's docblock for the full reasoning). This component is the UI over
 * it: list the REAL active zones, let the customer choose one, optionally
 * offer to pick the nearest covering zone from the browser's own
 * geolocation, and be honest when a location is outside every serviced
 * area rather than snapping to the least-far zone.
 *
 * Nothing here decides serviceability, pricing, or dispatch. Those stay
 * server-side in the existing booking pipeline, unchanged.
 */
class LocationPicker extends Component
{
    public bool $open = false;
    public string $search = '';

    /** Set when a geolocation lookup found no zone whose own radius reaches the point. */
    #[Locked]
    public bool $outOfCoverage = false;

    public function openPicker(): void
    {
        $this->open = true;
        $this->reset('search', 'outOfCoverage');
    }

    /** Let another component on the page (the homepage hero pill) open this one picker. */
    #[On('open-location-picker')]
    public function openFromElsewhere(): void
    {
        $this->openPicker();
    }

    public function closePicker(): void
    {
        $this->open = false;
        $this->reset('search', 'outOfCoverage');
    }

    public function selectZone(int $zoneId, CustomerLocationContext $context): void
    {
        // setZone() refuses an inactive/unknown id, so a tampered payload
        // cannot put an unusable zone into the session.
        if ($context->setZone($zoneId)) {
            $this->closePicker();
            $this->dispatch('customer-zone-changed');
        }
    }

    /**
     * Called from the browser's Geolocation callback. The coordinates are
     * only ever used to LOOK UP a zone — they are not stored, and the
     * resulting zone/franchise pairing still comes from the database, so a
     * spoofed coordinate can at most select a zone the customer could have
     * picked from the list by hand anyway.
     */
    public function useCurrentLocation(float $lat, float $lng, CustomerLocationContext $context): void
    {
        // Validated as loose arguments (not component state), so
        // Validator::make rather than $this->validate().
        Validator::make(
            ['lat' => $lat, 'lng' => $lng],
            [
                'lat' => ['required', 'numeric', 'between:-90,90'],
                'lng' => ['required', 'numeric', 'between:-180,180'],
            ],
        )->validate();

        $zone = $context->nearestCoveringZone($lat, $lng);

        if (! $zone) {
            $this->outOfCoverage = true;

            return;
        }

        $this->selectZone($zone->id, $context);
    }

    public function render()
    {
        $context = app(CustomerLocationContext::class);

        return view('livewire.customer.location-picker', [
            'activeZone' => $context->zone(),
            'zones' => $this->matchingZones(),
        ]);
    }

    /**
     * Active zones only, eager-loading franchise + city so the list can show
     * "Zone — City" without an N+1. Search matches the zone name, its code,
     * or its city name, since a customer thinks in city names while the
     * admin panel thinks in zone codes.
     */
    private function matchingZones(): Collection
    {
        $term = trim($this->search);

        return Zone::query()
            ->where('is_active', true)
            ->with('franchise.city')
            ->when($term !== '', fn ($query) => $query->where(function ($q) use ($term) {
                $q->where('name', 'like', '%'.$term.'%')
                    ->orWhere('code', 'like', '%'.$term.'%')
                    ->orWhereHas('franchise.city', fn ($c) => $c->where('name', 'like', '%'.$term.'%'));
            }))
            ->orderBy('name')
            ->limit(50)
            ->get();
    }
}
