<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The shared LOGIN/verification OTP engine (see OTP_ARCHITECTURE.md).
 * Deliberately NOT used for the Service booking start/completion OTP —
 * that remains on bookings.start_otp/completion_otp, a separate,
 * untouched, already-working mechanism. code_hash is hashed (Hash::make),
 * never plaintext — see OtpService.
 */
class Otp extends Model
{
    use HasFactory;

    protected $table = 'otps';

    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_LOCKED = 'locked';

    protected $fillable = [
        'phone',
        'code_hash',
        'purpose',
        'channel',
        'attempt_count',
        'max_attempts',
        'status',
        'last_sent_at',
        'ip_address',
        'device_identifier',
        'expires_at',
        'verified_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'last_sent_at' => 'datetime',
    ];

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function hasAttemptsRemaining(): bool
    {
        return $this->attempt_count < $this->max_attempts;
    }
}
