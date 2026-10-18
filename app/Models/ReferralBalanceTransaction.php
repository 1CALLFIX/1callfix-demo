<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of the referral balance ledger. Credits keep `remaining` and `expires_at` for oldest-first spending and expiry. */
class ReferralBalanceTransaction extends Model
{
    protected $fillable = [
        'referral_balance_id', 'amount', 'is_credit', 'remaining', 'expires_at', 'reason', 'ref', 'booking_id', 'actor_id',
    ];

    protected $casts = [
        'is_credit' => 'boolean',
        'amount' => 'decimal:2',
        'remaining' => 'decimal:2',
        'expires_at' => 'datetime',
    ];

    public function referralBalance() { return $this->belongsTo(ReferralBalance::class); }
    public function booking() { return $this->belongsTo(Booking::class); }
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
}
