<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class UserProtectionPlan extends Model
{
    use HasFactory;

    protected $table = 'user_protection_plans';

    protected $fillable = [
        'user_id',
        'protection_plan_id',
        'purchased_at',
        'expires_at',
        'status'
    ];

    protected $casts = ['purchased_at' => 'datetime', 'expires_at' => 'datetime'];
    public function user() { return $this->belongsTo(User::class); }
    public function plan() { return $this->belongsTo(ProtectionPlan::class, 'protection_plan_id'); }
}
