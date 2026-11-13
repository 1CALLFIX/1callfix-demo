<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Function halls R1: a day (or one slot of a day) the owner already sold outside 1CallFix. Customers cannot book it. */
class HallBlockedDate extends Model
{
    protected $fillable = ['hall_id', 'date', 'hall_slot_id', 'note'];

    protected $casts = ['date' => 'date'];

    public function hall() { return $this->belongsTo(Hall::class); }
    public function slot() { return $this->belongsTo(HallSlot::class, 'hall_slot_id'); }
}
