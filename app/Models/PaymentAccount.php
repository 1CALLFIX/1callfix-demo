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
}
