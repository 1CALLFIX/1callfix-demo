<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Parcel Stage 2 — one chat message between a parcel's sender and its rider. */
class ParcelOrderMessage extends Model
{
    protected $fillable = ['parcel_order_id', 'sender_id', 'body', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];

    public function parcelOrder() { return $this->belongsTo(ParcelOrder::class); }
    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
}
