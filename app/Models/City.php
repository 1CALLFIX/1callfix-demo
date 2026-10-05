<?php

namespace App\Models;

use App\Models\Concerns\HasManagedSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    use HasManagedSlug;

    protected $table = 'cities';

    protected $fillable = [
        'country_id',
        'name',
        'slug',
        'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function country() { return $this->belongsTo(Country::class); }
    public function franchises() { return $this->hasMany(Franchise::class); }

    /**
     * F3: a city has public pages only when it is switched on AND at least one franchise in it is active.
     * An inactive or franchise-less city (test data, a city not yet launched) never gets a page or sitemap entry.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('cities.is_active', true)
            ->whereHas('franchises', fn ($q) => $q->where('status', 'active'));
    }

    /** QA/demo cities are the ones QaSeeder names "[QA] ..." (see App\Support\Seo\QaRows). */
    public function scopeNotQa(Builder $query): Builder
    {
        return $query->where('cities.name', 'not like', \App\Support\Seo\QaRows::LIKE);
    }
}
