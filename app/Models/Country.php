<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    protected $table = 'countries';

    protected $fillable = [
        'name',
        'code',
        'currency_code',
        'default_timezone',
        'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function cities() { return $this->hasMany(City::class); }
    public function franchises() { return $this->hasMany(Franchise::class); }
}
