<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class ProviderSubscription extends Model
{
    use HasFactory;

    protected $table = 'provider_subscriptions';

    protected $fillable = [
        'provider_id',
        'subscription_plan_id',
        'credits_remaining',
        'status',
        'starts_at',
        'expires_at',
        'auto_renew'
    ];

    protected $casts = ['starts_at' => 'datetime', 'expires_at' => 'datetime', 'auto_renew' => 'boolean'];
    public function provider() { return $this->belongsTo(Provider::class); }
    public function plan() { return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id'); }
}
