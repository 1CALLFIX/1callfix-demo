<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceOrderStatusHistory extends Model
{
    protected $table = 'marketplace_order_status_history';

    protected $fillable = ['marketplace_order_id', 'status', 'changed_by', 'note', 'changed_at'];

    protected $casts = ['changed_at' => 'datetime'];

    public function order() { return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id'); }
    public function changedByUser() { return $this->belongsTo(User::class, 'changed_by'); }
}
