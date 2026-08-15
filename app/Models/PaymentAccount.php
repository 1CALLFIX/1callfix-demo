<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class PaymentAccount extends Model
{
    use HasFactory;

    protected $table = 'payment_accounts';

    protected $fillable = [
        'user_id',
        'account_type',
        'account_holder_name',
        'account_number',
        'ifsc',
        'upi_id',
        'is_verified',
        'is_default'
    ];

    protected $casts = ['is_verified' => 'boolean', 'is_default' => 'boolean'];

    public function user() { return $this->belongsTo(User::class); }
    public function payouts() { return $this->hasMany(Payout::class); }

    /**
     * Mission Phase 14 (Operations Import/Export completeness) — the
     * Payouts export needs a real reference to which account a payout was
     * settled to, but account_number/ifsc/upi_id are genuine banking
     * secrets that must never leave the app in a downloadable file. Last-4
     * only, same masking convention as RazorpayService::maskedPublicIdentifier().
     */
    public function getMaskedAccountNumberAttribute(): ?string
    {
        if ($this->upi_id) {
            return $this->upi_id;
        }

        if (! $this->account_number) {
            return null;
        }

        $last4 = substr($this->account_number, -4);

        return str_repeat('*', max(strlen($this->account_number) - 4, 0)).$last4;
    }
}
