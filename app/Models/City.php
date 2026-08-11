<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    protected $table = 'cities';

    protected $fillable = [
        'country_id',
        'name',
        'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function country() { return $this->belongsTo(Country::class); }
    public function franchises() { return $this->hasMany(Franchise::class); }
}
