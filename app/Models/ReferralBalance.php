<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Owner decision 2026-10-17 — referral money, kept apart from the wallet. Written only by ReferralBalanceService. */
class ReferralBalance extends Model
{
    protected $fillable = ['user_id', 'balance'];

    protected $casts = ['balance' => 'decimal:2'];

    public function user() { return $this->belongsTo(User::class); }
    public function transactions() { return $this->hasMany(ReferralBalanceTransaction::class); }
}
