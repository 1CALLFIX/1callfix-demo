<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusinessAccount extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'business_accounts';

    protected $fillable = [
        'owner_user_id', 'name', 'business_type', 'franchise_id', 'status', 'kyc_status',
    ];

    public function owner() { return $this->belongsTo(User::class, 'owner_user_id'); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function locations() { return $this->hasMany(BusinessLocation::class); }
    public function subscriptions() { return $this->morphMany(Subscription::class, 'subscribable'); }
}
