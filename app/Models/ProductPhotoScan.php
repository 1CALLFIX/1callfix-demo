<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A seller's photo of a product list being read into items (owner 2026-11-03). See App\Jobs\ReadProductPhotoJob. */
class ProductPhotoScan extends Model
{
    protected $fillable = ['store_id', 'user_id', 'status', 'photo_paths', 'items', 'error', 'input_tokens', 'output_tokens'];

    protected $casts = ['photo_paths' => 'array', 'items' => 'array'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['done', 'failed'], true);
    }
}
