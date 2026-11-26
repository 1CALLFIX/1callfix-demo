<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Owner 2026-11-26: a "Verified 1CallFix Partner" certificate for a business (App\Services\Partners\CertificateService).
 * One row per business (business_type store | hotel | hall | organiser). The token is the QR's /verify/{token}; a new
 * token (yearly renewal) makes the old printed certificate fail the scan.
 */
class BusinessCertificate extends Model
{
    protected $fillable = ['user_id', 'business_type', 'business_id', 'token', 'issued_at', 'valid_until'];

    protected $casts = ['issued_at' => 'datetime', 'valid_until' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }

    public function isExpired(): bool
    {
        return $this->valid_until->isPast();
    }
}
