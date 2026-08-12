<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A short-lived, single-use, purpose-bound QR/scan challenge — see
 * QR_SCAN_ARCHITECTURE.md. Never a permanent credential: `token` is opaque
 * high-entropy randomness with no embedded secret, and every challenge is
 * one-time-use (status flips to 'confirmed' the instant it's used, and a
 * confirmed/expired/revoked challenge can never be confirmed again).
 */
class QrChallenge extends Model
{
    public const PURPOSE_DEVICE_PAIRING = 'device_pairing';

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'token',
        'poll_token',
        'purpose',
        'status',
        'initiator_identifier',
        'initiator_ip',
        'confirmed_by_user_id',
        'confirmed_device_identifier',
        'confirmed_ip',
        'payload',
        'expires_at',
        'confirmed_at',
        'session_claimed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'session_claimed_at' => 'datetime',
    ];

    /** Never serialize poll_token — it's a secret, only ever returned once directly in create()'s HTTP response. */
    protected $hidden = ['poll_token'];

    public static function generateToken(): string
    {
        // 48 bytes of randomness, base64url-encoded -- opaque, unguessable,
        // carries no meaning or embedded secret of its own (see
        // QR_SCAN_ARCHITECTURE.md's "never a permanent credential" rule).
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    public function confirmedByUser()
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
