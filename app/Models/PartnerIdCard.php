<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Owner 2026-11-26: a 1CallFix ID card for the people who go to customers (App\Services\Partners\IdCardService).
 * One row per partner per app (holder_type provider | rider). The token is the QR's /verify/{token}; a new token
 * (lost card, yearly renewal) makes the old printed card fail the scan.
 */
class PartnerIdCard extends Model
{
    protected $fillable = ['user_id', 'holder_type', 'holder_id', 'token', 'issued_at', 'valid_until'];

    protected $casts = ['issued_at' => 'datetime', 'valid_until' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }

    public function isExpired(): bool
    {
        return $this->valid_until->isPast();
    }
}
