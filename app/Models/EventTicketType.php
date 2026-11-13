<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Events E1: one kind of ticket (Early Bird, Regular, VIP, Couple...) with its own price and quantity. */
class EventTicketType extends Model
{
    protected $fillable = [
        'event_id', 'name', 'description', 'price', 'quantity', 'sold', 'admits', 'max_per_order', 'sale_ends_at', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'integer',
        'sold' => 'integer',
        'admits' => 'integer',
        'max_per_order' => 'integer',
        'sale_ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function event() { return $this->belongsTo(Event::class); }

    public function left(): int
    {
        return max(0, $this->quantity - $this->sold);
    }
}
