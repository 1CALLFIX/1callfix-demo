<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Function halls R1: what a customer books (Full day, Morning, Evening...), with its own time and price. */
class HallSlot extends Model
{
    protected $fillable = ['hall_id', 'name', 'start_time', 'end_time', 'price', 'is_active', 'sort_order'];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function hall() { return $this->belongsTo(Hall::class); }

    /** True when the slot ends after midnight (e.g. 6 PM – 2 AM). */
    public function endsNextDay(): bool
    {
        return substr((string) $this->end_time, 0, 5) <= substr((string) $this->start_time, 0, 5);
    }

    /** "6:00 AM – 2:00 PM", "+1 day" when it runs past midnight. */
    public function timeLabel(): string
    {
        $f = fn ($t) => Carbon::createFromFormat('H:i', substr((string) $t, 0, 5))->format('g:i A');

        return $f($this->start_time).' – '.$f($this->end_time).($this->endsNextDay() ? ' (next day)' : '');
    }
}
