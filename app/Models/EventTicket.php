<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Events E2: one ticket. Its code is the QR shown at the gate; it lets `admits` people in, once. */
class EventTicket extends Model
{
    protected $fillable = ['event_order_id', 'event_id', 'event_ticket_type_id', 'code', 'admits', 'status', 'checked_in_at', 'checked_in_by'];

    protected $casts = [
        'admits' => 'integer',
        'checked_in_at' => 'datetime',
    ];

    public function order() { return $this->belongsTo(EventOrder::class, 'event_order_id'); }
    public function event() { return $this->belongsTo(Event::class); }
    public function ticketType() { return $this->belongsTo(EventTicketType::class, 'event_ticket_type_id'); }

    /** Long and random: it cannot be guessed from another ticket's code. */
    public static function newCode(): string
    {
        do {
            $code = 'T'.strtoupper(Str::random(11));
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
