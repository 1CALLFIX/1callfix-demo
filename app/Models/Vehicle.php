<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * RENTAL MODULE IMPLEMENTATION -- Vehicle inventory (the listing/catalog,
 * like `Property`). `RentalReservation` (rentable_type=Vehicle) is the
 * order. See the `vehicles` migration's own docblock for the reasoning
 * behind `supported_rental_modes`/`pricing_unit`.
 */
class Vehicle extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'vehicles';

    protected $fillable = [
        'provider_id', 'vehicle_category_id', 'franchise_id', 'zone_id',
        'make', 'model', 'year', 'fuel_type', 'transmission', 'seating_capacity',
        'registration_number', 'color', 'supported_rental_modes',
        'base_price', 'discount_price', 'pricing_unit', 'deposit_amount',
        'pickup_location_line', 'lat', 'lng', 'rental_terms', 'cover_image', 'is_active',
    ];

    protected $casts = [
        'supported_rental_modes' => 'array',
        'is_active' => 'boolean',
    ];

    public function provider() { return $this->belongsTo(Provider::class); }
    public function vehicleCategory() { return $this->belongsTo(VehicleCategory::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function reservations() { return $this->morphMany(RentalReservation::class, 'rentable'); }

    /** Whether this vehicle offers the given rental mode (e.g. 'self_drive'/'with_driver') -- the real validation point for CreateRentalReservationAction, not a hardcoded two-value check. */
    public function supportsRentalMode(string $mode): bool
    {
        return in_array($mode, $this->supported_rental_modes ?? [], true);
    }
}
