<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Zone extends Model
{
    use HasFactory;

    protected $table = 'zones';

    protected $fillable = [
        'franchise_id',
        'name',
        'boundary_polygon',
        'center_lat',
        'center_lng',
        'default_dispatch_radius_km',
        'is_active'
    ];

    protected $casts = ['boundary_polygon' => 'array', 'is_active' => 'boolean'];
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function providers() { return $this->hasMany(Provider::class); }
    public function bookings() { return $this->hasMany(Booking::class); }
}
