<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One item a customer liked (♡). See App\Support\SavedItems. */
class SavedItem extends Model
{
    protected $fillable = ['user_id', 'item_key', 'title', 'url', 'image_url'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
