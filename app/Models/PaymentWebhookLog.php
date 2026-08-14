<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentWebhookLog extends Model
{
    public $timestamps = false;

    protected $table = 'payment_webhook_logs';

    protected $fillable = [
        'gateway', 'event', 'gateway_order_id', 'gateway_payment_id', 'payment_id',
        'signature_valid', 'processed', 'outcome', 'payload', 'created_at',
    ];

    protected $casts = ['signature_valid' => 'boolean', 'processed' => 'boolean', 'payload' => 'array', 'created_at' => 'datetime'];

    public function payment() { return $this->belongsTo(Payment::class); }
}
