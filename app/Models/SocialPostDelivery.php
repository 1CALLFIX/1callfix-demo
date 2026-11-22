<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One post on one platform: marked "Done ✓" by hand, or written by the automatic publisher.
 * status: pending | posting (claimed) | sent (Buffer took it, waiting for it to go live) | posted | failed | skipped.
 * via: direct (our Facebook connection) | buffer | manual; external_id = Buffer's post id.
 */
class SocialPostDelivery extends Model
{
    protected $fillable = ['social_post_id', 'platform', 'status', 'via', 'posted_at', 'posted_by', 'post_url', 'external_id', 'error'];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }
}
