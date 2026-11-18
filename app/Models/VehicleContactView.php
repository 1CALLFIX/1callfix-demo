<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Vehicle for rent: a signed-in customer saw this owner's number (Rental Settings daily limit). */
class VehicleContactView extends Model
{
    protected $fillable = ['vehicle_listing_id', 'customer_id'];
}
