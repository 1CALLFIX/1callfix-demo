<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Events E2: one ticket type in an order, with the price as charged. */
class EventOrderItem extends Model
{
    protected $fillable = ['event_order_id', 'event_ticket_type_id', 'name', 'unit_price', 'gst_percent', 'quantity', 'admits', 'line_total'];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'gst_percent' => 'decimal:2',
        'line_total' => 'decimal:2',
        'quantity' => 'integer',
        'admits' => 'integer',
    ];

    public function order() { return $this->belongsTo(EventOrder::class, 'event_order_id'); }
    public function ticketType() { return $this->belongsTo(EventTicketType::class, 'event_ticket_type_id'); }
}
