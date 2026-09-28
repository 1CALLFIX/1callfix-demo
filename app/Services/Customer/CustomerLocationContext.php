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
 * A coordinate is resolved to a zone in two passes:
 *
 *   1. Point-in-polygon against `zones.boundary_polygon` — the actual shape
 *      an admin drew on the map. This is the authoritative test: if the
 *      point falls inside a zone's real boundary, that zone wins (the
 *      nearest one by centre distance if the point is inside more than one).
 *
 *   2. Fallback for a point no polygon contains: the NEAREST active zone
 *      whose own `center_lat`/`center_lng` + `default_dispatch_radius_km`
 *      circle reaches it. That radius is the same one dispatch already
 *      trusts (DispatchService::findCandidates()/nearbyForService()),
 *      measured with the same public DispatchService::haversineKm(). It
 *      keeps a usable answer for a zone whose polygon is missing or too
 *      tight, without ever snapping to a zone that is simply "least far".
 *
 * Pass 1 was added after the centre+radius circle alone (pass 2) was found
 * rejecting real users who were plainly inside a large city zone's drawn
 * boundary but more than the ~8-10 km radius from its centroid — the modal
 * told them "not serving your area" with that very zone listed right below.
 * The ray-casting in pointInPolygon() treats lat as y and lng as x on a
 * plane; over a city-sized zone the projection error is far below the
 * width of the boundary itself and does not change inside/outside for any
 * point not sitting almost exactly on an edge.
 *
 * Still a browsing convenience for choosing a starting zone, never an
 * authority on serviceability. Booking-time zone/franchise assignment
 * remains entirely server-side in the existing booking pipeline.
 */
class CustomerLocationContext
{
    public const SESSION_KEY = 'customer.zone_id';

    /**
     * Purely descriptive, non-authoritative companions to SESSION_KEY, added
     * for 1CF-HOMESCREEN-UX-001 so the location bar can show a real place
     * name/address instead of only the zone name. These NEVER drive zone,
     * franchise, pricing or dispatch decisions by themselves — they are only
     * ever written in the same request that also calls setZone() with a
     * value nearestCoveringZone()/setZone() has already validated, and they
     * are cleared together with the zone. No second source of truth: the
     * zone id in SESSION_KEY remains the only thing anything server-side
     * trusts for serviceability.
     */
    public const SESSION_KEY_LABEL = 'customer.location_label';
    public const SESSION_KEY_ADDRESS = 'customer.location_address';
    public const SESSION_KEY_LAT = 'customer.location_lat';
    public const SESSION_KEY_LNG = 'customer.location_lng';

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

    /**
     * Persists the chosen zone. Silently refuses an inactive/unknown zone
     * rather than storing an id that resolves to nothing.
     *
     * `$descriptive` is optional display-only metadata (label/address/lat/
     * lng) for the location bar — see the class docblock above on why it
     * can never be trusted for anything but display. Omitting it (the zone
     * list / "use current location" paths) clears any previously-stored
     * descriptive text so the bar falls back to the zone name rather than
     * showing a stale address for a different pick.
     *
     * @param  array{label?:?string,address?:?string,lat?:?float,lng?:?float}|null  $descriptive
     */
    public function setZone(int $zoneId, ?array $descriptive = null): bool
    {
        if (! Zone::where('is_active', true)->whereKey($zoneId)->exists()) {
            return false;
        }

        session([self::SESSION_KEY => $zoneId]);

        if ($descriptive) {
            session([
                self::SESSION_KEY_LABEL => $descriptive['label'] ?? null,
                self::SESSION_KEY_ADDRESS => $descriptive['address'] ?? null,
                self::SESSION_KEY_LAT => $descriptive['lat'] ?? null,
                self::SESSION_KEY_LNG => $descriptive['lng'] ?? null,
            ]);
        } else {
            session()->forget([
                self::SESSION_KEY_LABEL,
                self::SESSION_KEY_ADDRESS,
                self::SESSION_KEY_LAT,
                self::SESSION_KEY_LNG,
            ]);
        }

        return true;
    }

    /** Bold headline text for the location bar — a picked place's short name, or null to fall back to the zone name. */
    public function label(): ?string
    {
        return session(self::SESSION_KEY_LABEL);
    }

    /** The shorter address line under the bold label, or null when nothing more specific than the zone is known. */
    public function addressLine(): ?string
    {
        return session(self::SESSION_KEY_ADDRESS);
    }

    /** The lat/lng behind the current descriptive label, purely for display/recent-location purposes — never re-trusted for zone resolution. */
    public function coordinates(): ?array
    {
        $lat = session(self::SESSION_KEY_LAT);
        $lng = session(self::SESSION_KEY_LNG);

        if ($lat === null || $lng === null) {
            return null;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }

    public function clear(): void
    {
        session()->forget([
            self::SESSION_KEY,
            self::SESSION_KEY_LABEL,
            self::SESSION_KEY_ADDRESS,
            self::SESSION_KEY_LAT,
            self::SESSION_KEY_LNG,
        ]);
    }

    /**
     * The active zone that covers this point, or null when none does.
     *
     * Pass 1 — the point inside a zone's drawn `boundary_polygon`. When the
     * point is inside more than one, the nearest by centre distance wins
     * (a zone with no centre recorded sorts last). Pass 2 — for a point no
     * polygon contains, the nearest active zone whose own
     * `center_lat`/`center_lng` + `default_dispatch_radius_km` circle
     * reaches it. Zones with no centre are skipped in pass 2: they cannot
     * be measured, and treating a missing centre as (0,0) would drop every
     * such zone into the Gulf of Guinea.
     */
    public function nearestCoveringZone(float $lat, float $lng): ?Zone
    {
        $zones = Zone::with('franchise')->where('is_active', true)->get();

        // Pass 1 — real boundary.
        $insidePolygon = $zones
            ->filter(fn (Zone $zone) => $this->hasUsablePolygon($zone))
            ->filter(fn (Zone $zone) => $this->pointInPolygon($lat, $lng, $zone->boundary_polygon))
            ->sortBy(fn (Zone $zone) => $this->centreDistanceKm($lat, $lng, $zone))
            ->first();

        if ($insidePolygon) {
            return $insidePolygon;
        }

        // Pass 2 — centre + dispatch-radius circle, for anything with a centre.
        return $zones
            ->filter(fn (Zone $zone) => $zone->center_lat !== null && $zone->center_lng !== null)
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

    /** A boundary_polygon we can actually ray-cast against: an array of at least 3 points. */
    private function hasUsablePolygon(Zone $zone): bool
    {
        return is_array($zone->boundary_polygon) && count($zone->boundary_polygon) >= 3;
    }

    /** Kilometres from the point to the zone's recorded centre, or INF when it has none. */
    private function centreDistanceKm(float $lat, float $lng, Zone $zone): float
    {
        if ($zone->center_lat === null || $zone->center_lng === null) {
            return INF;
        }

        return $this->dispatchService->haversineKm($lat, $lng, (float) $zone->center_lat, (float) $zone->center_lng);
    }

    /**
     * Ray-casting point-in-polygon. `$polygon` is the stored shape —
     * `[['lat' => .., 'lng' => ..], ...]` — walked as (x=lng, y=lat) on a
     * plane (see this class's docblock on why the projection error is
     * negligible at zone scale). A point exactly on an edge may land either
     * way; that is acceptable for a browsing hint.
     */
    private function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $points = array_values(array_filter(
            $polygon,
            fn ($p) => is_array($p) && isset($p['lat'], $p['lng']),
        ));

        $count = count($points);
        if ($count < 3) {
            return false;
        }

        $inside = false;

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $yi = (float) $points[$i]['lat'];
            $xi = (float) $points[$i]['lng'];
            $yj = (float) $points[$j]['lat'];
            $xj = (float) $points[$j]['lng'];

            $straddlesRay = ($yi > $lat) !== ($yj > $lat);
            if (! $straddlesRay) {
                continue;
            }

            $denominator = $yj - $yi;
            if ($denominator === 0.0) {
                continue;
            }

            $intersectLng = ($xj - $xi) * ($lat - $yi) / $denominator + $xi;
            if ($lng < $intersectLng) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
