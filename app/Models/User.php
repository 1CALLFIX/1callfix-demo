<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'uuid',
        'name',
        'phone',
        'email',
        'password',
        'role',
        'franchise_id',
        'zone_id',
        'profile_photo',
        'preferred_language',
        'status',
        'phone_verified_at',
        'fcm_token',
        'referral_code',
        'referred_by'
    ];

    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['phone_verified_at' => 'datetime'];
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function providerProfile() { return $this->hasOne(Provider::class); }
    public function addresses() { return $this->hasMany(Address::class); }
    public function bookings() { return $this->hasMany(Booking::class, 'customer_id'); }
    public function wallet() { return $this->hasOne(Wallet::class); }
    public function ownedFranchises() { return $this->hasMany(Franchise::class, 'owner_user_id'); }
}