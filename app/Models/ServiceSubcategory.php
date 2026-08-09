<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ServiceSubcategory extends Model
{
    protected $table = 'service_subcategories';

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'icon',
        'image',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Same dual-source handling as ServiceCategory::image_url — see there. */
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

    public function category()
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function services()
    {
        return $this->hasMany(Service::class, 'subcategory_id');
    }
}
