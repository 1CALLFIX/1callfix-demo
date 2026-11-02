<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Food F1: one opening slot of a restaurant on one weekday (0 = Sunday). closes_at before opens_at = past midnight. */
class StoreHour extends Model
{
    protected $table = 'store_hours';

    protected $fillable = ['store_id', 'day', 'opens_at', 'closes_at'];

    protected $casts = ['day' => 'integer'];

    public function store() { return $this->belongsTo(Store::class); }
}
