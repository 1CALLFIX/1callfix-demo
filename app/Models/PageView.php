<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One customer page opened (Live Visitors, 2026-11-21). Written by App\Services\Visitors\VisitTracker only. */
class PageView extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'visitor_id', 'user_id', 'page_type', 'module', 'route_name', 'path', 'title', 'channel', 'referrer_host',
        'zone_id', 'franchise_id', 'city_id', 'country_id', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
