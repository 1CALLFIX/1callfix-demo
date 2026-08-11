<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class FranchiseServicePricing extends Model
{
    use HasFactory;

    protected $table = 'franchise_service_pricing';

    protected $fillable = [
        'franchise_id',
        'service_id',
        'price_override',
        'is_offered'
    ];

    protected $casts = ['is_offered' => 'boolean'];

    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function service() { return $this->belongsTo(Service::class); }
}
