<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Rent anything: a signed-in customer saw this item owner's number (daily limit, and "seen once, shown again"). */
class RentalItemContactView extends Model
{
    protected $fillable = ['rental_item_id', 'customer_id'];
}
