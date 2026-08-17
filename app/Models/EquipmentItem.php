<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * RENTAL MODULE IMPLEMENTATION -- Equipment/Machinery inventory (the
 * listing/catalog, like `Property`/`Vehicle`). `RentalReservation`
 * (rentable_type=EquipmentItem) is the order.
 */
class EquipmentItem extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'equipment_items';

    protected $fillable = [
        'provider_id', 'equipment_category_id', 'franchise_id', 'zone_id',
        'name', 'manufacturer', 'model', 'specifications', 'condition',
        'operating_hours', 'accessories', 'maintenance_state',
        'base_price', 'discount_price', 'pricing_unit', 'deposit_amount',
        'location_line', 'lat', 'lng', 'rental_terms', 'cover_image', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function provider() { return $this->belongsTo(Provider::class); }
    public function equipmentCategory() { return $this->belongsTo(EquipmentCategory::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function reservations() { return $this->morphMany(RentalReservation::class, 'rentable'); }
}
