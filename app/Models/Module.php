<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The vertical registry (master doc §15) — data, not the PHP constant.
 * Seeded from App\Support\Modules::ALL, which remains in place as the
 * shared code/label source Categories/Subcategories/Services already
 * depend on; this table exists for things that need a real row to attach
 * to (settings scope, future per-vertical config), not to replace that
 * shared list.
 */
class Module extends Model
{
    protected $table = 'modules';

    protected $fillable = [
        'code',
        'name',
        'sort_order',
        'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];
}
