<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreAddressRequest;
use App\Http\Requests\Customer\UpdateAddressRequest;
use App\Http\Resources\Customer\AddressResource;
use App\Models\Address;
use App\Models\Booking;
use App\Models\Zone;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;

/**
 * P0 Customer Core API — Customer addresses CRUD (mission item 6). Ownership
 * is enforced the same IDOR-safe, 404-not-403 way every other self-service
 * endpoint in this codebase already does (see `PaymentAccountController`) —
 * `user_id` is always checked server-side, never trusted from the URL alone.
 *
 * `is_default` is stored exactly as submitted, with NO automatic
 * "unset every other address's default" side effect: a real repository
 * search before writing this controller found zero existing code anywhere
 * that enforces `is_default` exclusivity — `addresses.is_default` has never
 * had a real writer before this endpoint (`Address::create()`'s only
 * existing callers are test/QA fixtures). Per the mission's own "default-
 * address behavior IF such behavior already exists" instruction, no
 * exclusivity rule is invented here — logged instead as
 * `KNOWN_RISKS_AND_DECISIONS.md` item 41.
 */
class AddressController extends Controller
{
    /** GET /api/addresses */
    public function index(Request $request)
    {
        $addresses = Address::where('user_id', $request->user()->id)->orderByDesc('is_default')->latest()->get();

        return ApiResponse::success(AddressResource::collection($addresses));
    }

    /** POST /api/addresses — `franchise_id` is derived from the chosen `zone_id`, never accepted directly (see StoreAddressRequest's own docblock). */
    public function store(StoreAddressRequest $request)
    {
        $validated = $request->validated();
        $zone = Zone::find($validated['zone_id']);
        if (! $zone) {
            return ApiResponse::error('Zone not found.', 404);
        }

        $address = Address::create([
            'user_id' => $request->user()->id,
            'franchise_id' => $zone->franchise_id,
            'zone_id' => $zone->id,
            'label' => $validated['label'] ?? 'Home',
            'lat' => $validated['lat'],
            'lng' => $validated['lng'],
            'address_line' => $validated['address_line'],
            'landmark' => $validated['landmark'] ?? null,
            'city' => $validated['city'] ?? null,
            'pincode' => $validated['pincode'] ?? null,
            'is_default' => $validated['is_default'] ?? false,
        ]);

        return ApiResponse::success(new AddressResource($address), 'Address created.', 201);
    }

    /** GET /api/addresses/{id} */
    public function show(Request $request, int $addressId)
    {
        $address = Address::where('id', $addressId)->where('user_id', $request->user()->id)->first();
        if (! $address) {
            return ApiResponse::error('Address not found.', 404);
        }

        return ApiResponse::success(new AddressResource($address));
    }

    /** PUT /api/addresses/{id} */
    public function update(UpdateAddressRequest $request, int $addressId)
    {
        $address = Address::where('id', $addressId)->where('user_id', $request->user()->id)->first();
        if (! $address) {
            return ApiResponse::error('Address not found.', 404);
        }

        $validated = $request->validated();

        if (array_key_exists('zone_id', $validated)) {
            $zone = Zone::find($validated['zone_id']);
            if (! $zone) {
                return ApiResponse::error('Zone not found.', 404);
            }
            $address->zone_id = $zone->id;
            $address->franchise_id = $zone->franchise_id;
            unset($validated['zone_id']);
        }

        $address->fill($validated);
        $address->save();

        return ApiResponse::success(new AddressResource($address->fresh()), 'Address updated.');
    }

    /**
     * DELETE /api/addresses/{id} — `bookings.address_id` is a real
     * `cascadeOnDelete()` foreign key (`create_bookings_table` migration):
     * deleting an address referenced by a booking would silently cascade-
     * delete that booking (a real financial/audit record), not just the
     * address. Guarded here at the application layer — the one real
     * technical defect this P0 mission's own instruction (item 13) calls
     * for fixing directly, since it's this exact endpoint that would
     * trigger it. Scoped to `Booking` only (the P0 vertical); the same
     * cascade exists on `parcel_orders`/`taxi_rides`/`marketplace_orders`'
     * address FKs too, out of scope until those customer APIs are built —
     * see `KNOWN_RISKS_AND_DECISIONS.md` item 41.
     */
    public function destroy(Request $request, int $addressId)
    {
        $address = Address::where('id', $addressId)->where('user_id', $request->user()->id)->first();
        if (! $address) {
            return ApiResponse::error('Address not found.', 404);
        }

        if (Booking::where('address_id', $address->id)->exists()) {
            return ApiResponse::error('This address is used by an existing booking and cannot be deleted.', 409);
        }

        $address->delete();

        return ApiResponse::success(null, 'Address deleted.');
    }
}
