<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class ProtectionPlan extends Model
{
    use HasFactory;

    protected $table = 'protection_plans';

    protected $fillable = [
        'name',
        'slug',
        'price',
        'duration_months',
        'benefits',
        'is_active'
    ];
    protected $casts = ['benefits' => 'array'];
}
