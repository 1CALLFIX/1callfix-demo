<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One post on one platform: marked "Done ✓" by hand today, written by the automatic publisher later. */
class SocialPostDelivery extends Model
{
    protected $fillable = ['social_post_id', 'platform', 'status', 'posted_at', 'posted_by', 'post_url', 'error'];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }
}
