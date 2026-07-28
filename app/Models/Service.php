<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'services';

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'base_price',
        'discount_price',
        'price_type',
        'duration_estimate_mins',
        'cover_image',
        'is_active'
    ];

    public function category() { return $this->belongsTo(ServiceCategory::class, 'category_id'); }
    public function optionGroups() { return $this->hasMany(ServiceOptionGroup::class); }
    public function franchisePricing() { return $this->hasMany(FranchiseServicePricing::class); }
    public function bookings() { return $this->hasMany(Booking::class); }
}
