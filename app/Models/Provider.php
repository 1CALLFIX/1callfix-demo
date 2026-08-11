<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Provider extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'providers';

    protected $fillable = [
        'user_id',
        'franchise_id',
        'zone_id',
        'provider_type',
        'parent_provider_id',
        'skills',
        'kyc_status',
        'credit_balance',
        'is_online',
        'current_lat',
        'current_lng',
        'location_updated_at',
        'rating_avg',
        'priority',
        'jobs_completed',
        'is_active'
    ];

    protected $casts = ['skills' => 'array', 'is_online' => 'boolean', 'location_updated_at' => 'datetime'];
    public function user() { return $this->belongsTo(User::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function parent() { return $this->belongsTo(Provider::class, 'parent_provider_id'); }
    public function technicians() { return $this->hasMany(Provider::class, 'parent_provider_id'); }
    public function documents() { return $this->hasMany(ProviderDocument::class); }
    public function subscriptions() { return $this->hasMany(ProviderSubscription::class); }
    public function bookings() { return $this->hasMany(Booking::class); }
    public function reviews() { return $this->hasMany(Review::class); }
}
