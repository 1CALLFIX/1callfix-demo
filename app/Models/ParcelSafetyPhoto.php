<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Parcel pickup safety (2026-10-29): a photo a rider took because something looked unusual. The file lives on the
 * PRIVATE disk; admins open it through ParcelSafetyPhotoController (parcel_orders.view at the order's scope).
 */
class ParcelSafetyPhoto extends Model
{
    protected $fillable = [
        'parcel_order_id', 'field_worker_id', 'stage', 'concerns', 'note', 'disk_path', 'lat', 'lng', 'taken_at',
    ];

    protected $casts = [
        'concerns' => 'array',
        'taken_at' => 'datetime',
    ];

    public function parcelOrder() { return $this->belongsTo(ParcelOrder::class); }
    public function fieldWorker() { return $this->belongsTo(FieldWorker::class); }
}
