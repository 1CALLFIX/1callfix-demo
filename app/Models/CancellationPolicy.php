<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class CancellationPolicy extends Model
{
    use HasFactory;

    protected $table = 'cancellation_policies';

    protected $fillable = [
        'franchise_id',
        'free_cancellation_minutes',
        'fee_type',
        'fee_value'
    ];
    public function franchise() { return $this->belongsTo(Franchise::class); }
}
