<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Where one visitor is right now (Live Visitors, 2026-11-21). Written by App\Services\Visitors\VisitTracker only. */
class VisitorPresence extends Model
{
    protected $table = 'visitor_presence';

    protected $primaryKey = 'visitor_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'visitor_id', 'user_id', 'page_type', 'module', 'path', 'title', 'channel',
        'zone_id', 'franchise_id', 'city_id', 'country_id', 'pages', 'started_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'last_seen_at' => 'datetime', 'pages' => 'integer'];
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
