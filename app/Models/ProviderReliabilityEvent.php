<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderReliabilityEvent extends Model
{
    protected $fillable = ['provider_id', 'booking_id', 'type', 'points', 'note'];

    public function provider() { return $this->belongsTo(Provider::class); }
}
