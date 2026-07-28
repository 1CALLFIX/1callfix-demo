<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class ProviderBadge extends Model
{
    use HasFactory;

    protected $table = 'provider_badges';

    protected $fillable = [
        'provider_id',
        'badge',
        'awarded_at'
    ];
    protected $casts = ['awarded_at' => 'datetime'];
    public function provider() { return $this->belongsTo(Provider::class); }
}
