<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Franchise extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'franchises';

    protected $fillable = [
        'name',
        'slug',
        'code',
        'city',
        'state',
        'country',
        'owner_user_id',
        'commission_model',
        'commission_value',
        'platform_fee_percent',
        'status'
    ];


    /**
     * e.g. "NELLORE - NLR" — for admin panel dropdowns/listings.
     */
    public function getDisplayNameAttribute(): string
    {
        return strtoupper($this->name) . ' - ' . strtoupper($this->code ?? '');
    }

    public function owner() { return $this->belongsTo(User::class, 'owner_user_id'); }
    public function zones() { return $this->hasMany(Zone::class); }
    public function users() { return $this->hasMany(User::class); }
    public function providers() { return $this->hasMany(Provider::class); }
    public function bookings() { return $this->hasMany(Booking::class); }
    public function servicePricing() { return $this->hasMany(FranchiseServicePricing::class); }
    public function modules() { return $this->hasOne(FranchiseModule::class); }
}
