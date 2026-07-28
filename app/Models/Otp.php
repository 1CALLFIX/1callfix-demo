<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Otp extends Model
{
    use HasFactory;

    protected $table = 'otps';

    protected $fillable = [
        'phone',
        'code',
        'purpose',
        'expires_at',
        'verified_at'
    ];
    protected $casts = ['expires_at' => 'datetime', 'verified_at' => 'datetime'];
}
