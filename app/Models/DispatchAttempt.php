<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class DispatchAttempt extends Model
{
    use HasFactory;

    protected $table = 'dispatch_attempts';

    protected $fillable = [
        'booking_id',
        'provider_id',
        'notifiable_type',
        'notifiable_id',
        'dispatchable_type',
        'dispatchable_id',
        'status',
        'distance_km',
        'notified_at',
        'responded_at'
    ];

    protected $casts = ['notified_at' => 'datetime', 'responded_at' => 'datetime'];
    public function booking() { return $this->belongsTo(Booking::class); }
    public function provider() { return $this->belongsTo(Provider::class); }

    /** Phase B0.3 -- WORKER side: who was notified (Provider, historically via provider_id above; a FieldWorker for platform-direct dispatch, via this instead). */
    public function notifiable() { return $this->morphTo(); }

    /** Phase 22.4 -- ORDER side: which order this offer belongs to. Booking's own findCandidates() path keeps writing booking_id only and never populates this -- only the new Parcel dispatch path does. */
    public function dispatchable() { return $this->morphTo(); }
}
