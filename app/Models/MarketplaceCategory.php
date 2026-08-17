<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Phase 24 (Marketplace Foundation) — self-referential taxonomy, module-scoped. See PHASE_24_MARKETPLACE_FOUNDATION_ARCHITECTURE.md §3. */
class MarketplaceCategory extends Model
{
    use HasFactory;

    protected $table = 'marketplace_categories';

    protected $fillable = ['parent_id', 'module', 'name', 'slug', 'image', 'position', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (MarketplaceCategory $category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name).'-'.Str::random(6);
            }
        });
    }

    public function parent() { return $this->belongsTo(MarketplaceCategory::class, 'parent_id'); }
    public function children() { return $this->hasMany(MarketplaceCategory::class, 'parent_id'); }
    public function products() { return $this->hasMany(Product::class); }
}
