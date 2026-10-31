<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Parcel Stage 2 — the sender's rating (1-5) of the rider after delivery. One per parcel. */
class ParcelRating extends Model
{
    protected $fillable = ['parcel_order_id', 'customer_id', 'field_worker_id', 'rating', 'comment'];

    protected $casts = ['rating' => 'integer'];

    public function parcelOrder() { return $this->belongsTo(ParcelOrder::class); }
    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function fieldWorker() { return $this->belongsTo(FieldWorker::class); }
}
