<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Property for rent: a signed-in customer saw this owner's number (Rental Settings daily limit). */
class HouseContactView extends Model
{
    protected $fillable = ['house_listing_id', 'customer_id'];
}
