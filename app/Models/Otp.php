<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The custom EMAIL verification / password-reset OTP engine (see
 * OTP_ARCHITECTURE.md). Since the auth rebuild this is no longer a LOGIN
 * mechanism — phone verification moved to Firebase and login is
 * password-based — it now issues numeric codes to an email address
 * (`identifier`, `channel = 'email'`) for signup verification and password
 * reset. Still deliberately NOT used for the Service booking
 * start/completion OTP, which remains on bookings.start_otp/completion_otp,
 * a separate untouched mechanism. code_hash is hashed (Hash::make), never
 * plaintext — see OtpService.
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
        'identifier',
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
