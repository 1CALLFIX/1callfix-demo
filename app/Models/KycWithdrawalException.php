<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class KycWithdrawalException extends Model
{
    protected $table = 'kyc_withdrawal_exceptions';

    protected $fillable = ['provider_id', 'granted_by', 'reason', 'starts_at', 'expires_at', 'revoked_at', 'revoked_by'];

    protected $casts = ['starts_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function provider() { return $this->belongsTo(Provider::class); }
    public function grantedBy() { return $this->belongsTo(User::class, 'granted_by'); }
    public function revokedBy() { return $this->belongsTo(User::class, 'revoked_by'); }

    /** Not revoked, already started, and not yet expired — the ONLY condition that suppresses a withdrawal restriction. */
    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->whereNull('revoked_at')
            ->where('starts_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now));
    }
}
