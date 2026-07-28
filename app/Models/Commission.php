<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Commission extends Model
{
    use HasFactory;

    protected $table = 'commissions';

    protected $fillable = [
        'booking_id',
        'provider_commission',
        'franchise_commission',
        'platform_commission'
    ];
    public function booking() { return $this->belongsTo(Booking::class); }
}
