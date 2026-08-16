<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParcelOrderStatusHistory extends Model
{
    protected $table = 'parcel_order_status_history';

    protected $fillable = ['parcel_order_id', 'status', 'changed_by', 'note', 'changed_at'];

    protected $casts = ['changed_at' => 'datetime'];

    public function parcelOrder() { return $this->belongsTo(ParcelOrder::class); }
    public function changedByUser() { return $this->belongsTo(User::class, 'changed_by'); }
}
