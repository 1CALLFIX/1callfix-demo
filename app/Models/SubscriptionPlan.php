<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $table = 'subscription_plans';

    protected $fillable = [
        'name',
        'price',
        'duration_days',
        'credits_included',
        'benefits',
        'is_active'
    ];
    protected $casts = ['benefits' => 'array'];
}
