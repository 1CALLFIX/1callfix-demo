<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingSequence extends Model
{
    protected $table = 'booking_sequences';

    protected $fillable = [
        'franchise_id',
        'sequence_date',
        'last_number',
    ];

    protected $casts = [
        'sequence_date' => 'date',
    ];

    public function franchise()
    {
        return $this->belongsTo(Franchise::class);
    }
}
