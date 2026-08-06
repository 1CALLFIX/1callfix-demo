<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranchiseModule extends Model
{
    protected $table = 'franchise_modules';

    protected $fillable = [
        'franchise_id',
        'service',
        'food',
        'parcel',
        'grocery',
        'pharmacy',
        'commerce',
        'bookings',
    ];

    protected $casts = [
        'service' => 'boolean',
        'food' => 'boolean',
        'parcel' => 'boolean',
        'grocery' => 'boolean',
        'pharmacy' => 'boolean',
        'commerce' => 'boolean',
        'bookings' => 'boolean',
    ];

    public function franchise()
    {
        return $this->belongsTo(Franchise::class);
    }
}
