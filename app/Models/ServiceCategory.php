<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class ServiceCategory extends Model
{
    use HasFactory;

    protected $table = 'service_categories';

    protected $fillable = [
        'parent_id',
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

    public function parent() { return $this->belongsTo(ServiceCategory::class, 'parent_id'); } // deprecated, kept for backward compat, unused going forward
    public function children() { return $this->hasMany(ServiceCategory::class, 'parent_id'); } // deprecated, kept for backward compat, unused going forward
    public function subcategories() { return $this->hasMany(ServiceSubcategory::class, 'category_id'); }
    public function services() { return $this->hasMany(Service::class, 'category_id'); }
}
