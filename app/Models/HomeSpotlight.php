<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * REF 1CF-HOME-SPOTLIGHT-001 — one numbered tile of the home page collage,
 * pointing at a service or a whole category.
 */
class HomeSpotlight extends Model
{
    public const SLOTS = 6;

    public const TYPES = ['service' => 'Service', 'category' => 'Category'];

    protected $fillable = ['position', 'target_type', 'target_id', 'badge', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
