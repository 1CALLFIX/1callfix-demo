<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Payout extends Model
{
    use HasFactory;

    protected $table = 'payouts';

    protected $fillable = [
        'payee_type',
        'payee_id',
        'payment_account_id',
        'amount',
        'period_start',
        'period_end',
        'status',
        'gateway_ref',
        'processed_at'
    ];
    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'processed_at' => 'datetime'];
}
