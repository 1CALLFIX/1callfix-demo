<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;


class ServiceCategory extends Model
{
    use HasFactory;

    protected $table = 'service_categories';

    protected $fillable = [
        'parent_id',
        'external_id',
        'module',
        'name',
        'slug',
        'icon',
        'color',
        'image',
        'description',
        'sort_order',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * `image` holds one of two things depending on where the row came from:
     * a path on the `public` disk (categories/xyz.png) for anything uploaded
     * through the admin panel, or a full CDN URL for rows imported from
     * Glover's sheets. Resolve both to something an <img src> can use rather
     * than making every call site check.
     */
    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        if (Str::startsWith($this->image, ['http://', 'https://', '//', 'data:'])) {
            return $this->image;
        }

        return Storage::disk('public')->url($this->image);
    }

    public function parent() { return $this->belongsTo(ServiceCategory::class, 'parent_id'); } // deprecated, kept for backward compat, unused going forward
    public function children() { return $this->hasMany(ServiceCategory::class, 'parent_id'); } // deprecated, kept for backward compat, unused going forward
    public function subcategories() { return $this->hasMany(ServiceSubcategory::class, 'category_id'); }
    public function services() { return $this->hasMany(Service::class, 'category_id'); }
}
