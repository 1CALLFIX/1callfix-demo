<?php

namespace App\Services\Customer;

use App\Models\Zone;
use App\Services\DispatchService;

/**
 * The customer web app's "where am I ordering from" context (Phase B).
 *
 * Holds ONE thing in the session: the id of a real, active `zones` row. The
 * franchise is never stored and never accepted from the client — it is
 * always re-derived server-side from `zone->franchise_id`, which is exactly
 * the rule App\Http\Controllers\API\AddressController::store() already
 * enforces ("`franchise_id` is derived from the chosen `zone_id`, never
 * accepted directly"). A client that tampers with the session cookie still
 * cannot manufacture a franchise/zone pairing that does not exist in the
 * database, and a zone that has since been deactivated resolves to null
 * rather than silently continuing to scope anything.
 *
 * ── On geolocation ────────────────────────────────────────────────────────
 * This class deliberately does NOT do point-in-polygon matching against
 * `zones.boundary_polygon`. A full-repository search before writing this
 * confirmed that column has no reader anywhere in `app/` — no geo-boundary
 * resolution has ever existed in this codebase, only its storage. Inventing
 * one here would be inventing zone boundaries, and getting it subtly wrong
 * would silently misroute real bookings in Phase D.
 *
 * What it does instead reuses primitives that DO already exist and are
 * already trusted by dispatch: a zone's own `center_lat`/`center_lng` and
 * its own `default_dispatch_radius_km` (the same radius
 * DispatchService::findCandidates()/nearbyForService() use to decide who
 * can be dispatched), measured with the same public
 * DispatchService::haversineKm(). A coordinate is matched to the NEAREST
 * active zone whose own service radius actually reaches it. If no zone's
 * radius reaches the point, that is reported honestly as "not covered"
 * rather than guessed at by snapping to whichever zone happens to be least
 * far away.
 *
 * This is a coarser answer than a real boundary check and is documented as
 * such — it is a browsing convenience for choosing a starting zone, never
 * an authority on serviceability. Booking-time zone/franchise assignment
 * remains entirely server-side in the existing booking pipeline.
 */
class CustomerLocationContext
{
    public const SESSION_KEY = 'customer.zone_id';

    public function __construct(private DispatchService $dispatchService)
    {
    }

    /** The active zone, or null when none is chosen (or the chosen one has since been deactivated/deleted). */
    public function zone(): ?Zone
    {
        $zoneId = session(self::SESSION_KEY);

        if (! $zoneId) {
            return null;
        }

        return Zone::with('franchise')->where('is_active', true)->find($zoneId);
    }

    /**
     * Franchise id for the active zone, or null. Always derived, never read
     * back from the session — see this class's docblock.
     */
    public function franchiseId(): ?int
    {
        return $this->zone()?->franchise_id;
    }

    /**
     * The viewer's resolved location as the exact array shape
     * AuthorizationService::scopeCovers() expects — and therefore the shape
     * BadgeService::badgesFor() and FlashSaleService::priceFor() both already
     * take as their `$viewerScope` argument (Phase C).
     *
     * Derived entirely from the one session-held zone id: zone -> franchise
     * -> city/country, all read back from the database. Nothing here is
     * accepted from the client, for the same reason franchiseId() is
     * derived rather than stored (see this class's docblock).
     *
     * An empty array when no zone is chosen. That is the correct, safe
     * answer rather than a problem to paper over: scopeCovers() treats a
     * missing key as "not in that scope", so an anonymous browser sees only
     * globally-scoped badges and flash sales — never one targeted at a zone
     * they have not told us they are in.
     *
     * @return array{zone_id?:int, franchise_id?:int, city_id?:int, country_id?:int}
     */
    public function viewerScope(): array
    {
        $zone = $this->zone();

        if (! $zone) {
            return [];
        }

        return array_filter([
            'zone_id' => $zone->id,
            'franchise_id' => $zone->franchise_id,
            'city_id' => $zone->franchise?->city_id,
            'country_id' => $zone->franchise?->country_id,
        ], fn ($value) => $value !== null);
    }

    /**
     * The targeting context Banner::scopeForSlot() expects. Same derivation
     * as viewerScope(), different key vocabulary — the banner table targets
     * on `module` (a vertical) as well as geography, and does not target on
     * city/country at all. Always pinned to the Service module: the customer
     * web app's launch experience is the Service vertical, and a banner sold
     * against Marketplace or Hotel must not surface on it.
     *
     * @return array{franchise_id?:int, zone_id?:int, module:string, category_id?:int}
     */
    public function bannerContext(?int $categoryId = null): array
    {
        $zone = $this->zone();

        return array_filter([
            'franchise_id' => $zone?->franchise_id,
            'zone_id' => $zone?->id,
            'module' => \App\Support\Modules::SERVICE,
            'category_id' => $categoryId,
        ], fn ($value) => $value !== null);
    }

    /** Persists the chosen zone. Silently refuses an inactive/unknown zone rather than storing an id that resolves to nothing. */
    public function setZone(int $zoneId): bool
    {
        if (! Zone::where('is_active', true)->whereKey($zoneId)->exists()) {
            return false;
        }

        session([self::SESSION_KEY => $zoneId]);

        return true;
    }

    public function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * Nearest active zone whose OWN dispatch radius reaches this point, or
     * null when none does. Zones with no centre coordinate recorded are
     * skipped — they cannot be measured against, and treating a missing
     * centre as (0,0) would put every such zone in the Gulf of Guinea.
     */
    public function nearestCoveringZone(float $lat, float $lng): ?Zone
    {
        return Zone::with('franchise')
            ->where('is_active', true)
            ->whereNotNull('center_lat')
            ->whereNotNull('center_lng')
            ->get()
            ->map(fn (Zone $zone) => [
                'zone' => $zone,
                'distance_km' => $this->dispatchService->haversineKm(
                    $lat,
                    $lng,
                    (float) $zone->center_lat,
                    (float) $zone->center_lng,
                ),
                'radius_km' => (float) ($zone->default_dispatch_radius_km ?? 8),
            ])
            ->filter(fn (array $row) => $row['distance_km'] <= $row['radius_km'])
            ->sortBy('distance_km')
            ->first()['zone'] ?? null;
    }
}
