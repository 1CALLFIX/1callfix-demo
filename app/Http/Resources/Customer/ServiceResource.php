<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `effective_price` is DISPLAY-ONLY — a preview of what `Service::
 * resolvePrice()` would compute for the given `?franchise_id=`, purely so a
 * browsing customer can see a realistic number before booking. The actual
 * booking charge is always recomputed server-side inside
 * BookingController::store(), never taken from anything this resource (or
 * any other client-visible value) returns — see that controller's own
 * docblock.
 */
class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $franchiseId = $request->integer('franchise_id') ?: null;

        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'subcategory_id' => $this->subcategory_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'base_price' => (float) $this->base_price,
            'discount_price' => $this->discount_price !== null ? (float) $this->discount_price : null,
            'effective_price' => $this->resource->resolvePrice($franchiseId),
            'price_type' => $this->price_type,
            'price_type_label' => $this->price_type_label,
            'duration_estimate_mins' => $this->duration_estimate_mins,
            'cover_image_url' => $this->cover_image_url,
            'location_required' => (bool) $this->location_required,
            'age_restriction' => (bool) $this->age_restriction,
        ];
    }
}
