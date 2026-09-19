<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Address extends Model
{
    use HasFactory;

    protected $table = 'addresses';

    protected $fillable = [
        'user_id',
        'franchise_id',
        'zone_id',
        'label',
        'lat',
        'lng',
        'address_line',
        'landmark',
        'city',
        'pincode',
        'is_default'
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }

    /**
     * True while a live (or awaiting-payment) membership is registered to this
     * address. Deleting it would silently strip an address-locked membership of
     * its lock (the FK is nullOnDelete), so both delete paths refuse instead.
     */
    public function registersLiveMembership(): bool
    {
        return \App\Models\Subscription::where('registered_address_id', $this->id)
            ->whereIn('status', ['pending_payment', 'active', 'grace_period', 'past_due', 'paused'])
            ->exists();
    }
}
