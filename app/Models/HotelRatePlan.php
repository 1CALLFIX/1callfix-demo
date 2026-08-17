<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** HOTEL / STAY BOOKING MODULE -- a rate plan under one HotelRoomType (Standard/Breakfast Included/Non-Refundable/...). */
class HotelRatePlan extends Model
{
    use HasFactory;

    protected $table = 'hotel_rate_plans';

    protected $fillable = [
        'hotel_room_type_id', 'name', 'meal_plan', 'cancellation_policy_label',
        'nightly_rate', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function roomType() { return $this->belongsTo(HotelRoomType::class, 'hotel_room_type_id'); }
}
