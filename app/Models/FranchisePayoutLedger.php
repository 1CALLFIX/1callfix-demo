<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class FranchisePayoutLedger extends Model
{
    use HasFactory;

    protected $table = 'franchise_payout_ledger';

    protected $fillable = [
        'franchise_id',
        'booking_id',
        'commission_earned',
        'status'
    ];

    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function booking() { return $this->belongsTo(Booking::class); }
}
