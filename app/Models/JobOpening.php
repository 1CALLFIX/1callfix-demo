<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A job on the public Careers page (owner 2026-11-21). Edited on Admin → Careers; shown on /careers and
 * /careers/{slug}. A closed job keeps its page (with "This job is closed") so a shared link never breaks.
 */
class JobOpening extends Model
{
    use SoftDeletes;

    public const TYPES = [
        'full_time' => 'Full-time',
        'part_time' => 'Part-time',
        'internship' => 'Internship',
        'contract' => 'Contract',
    ];

    /** schema.org JobPosting employmentType for each type. */
    public const SCHEMA_TYPES = [
        'full_time' => 'FULL_TIME', 'part_time' => 'PART_TIME', 'internship' => 'INTERN', 'contract' => 'CONTRACTOR',
    ];

    protected $fillable = [
        'title', 'slug', 'team', 'employment_type', 'location', 'region', 'openings', 'pay_text', 'summary',
        'duties', 'requirements', 'perks', 'needs_two_wheeler', 'apply_by', 'is_open', 'sort_order',
    ];

    protected $casts = [
        'duties' => 'array',
        'requirements' => 'array',
        'perks' => 'array',
        'needs_two_wheeler' => 'boolean',
        'is_open' => 'boolean',
        'apply_by' => 'date',
        'openings' => 'integer',
        'sort_order' => 'integer',
    ];

    public function applications()
    {
        return $this->hasMany(JobApplication::class);
    }

    /** Open, and the apply-by date (if any) has not passed. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_open', true)
            ->where(fn ($q) => $q->whereNull('apply_by')->orWhereDate('apply_by', '>=', now()->toDateString()))
            ->orderBy('sort_order')->orderBy('id');
    }

    public function isAccepting(): bool
    {
        return ! $this->trashed() && $this->is_open && ($this->apply_by === null || $this->apply_by->endOfDay()->isFuture());
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->employment_type] ?? 'Full-time';
    }

    public function url(): string
    {
        return route('customer.careers.show', $this->slug);
    }

    /** A clean, readable slug; -2, -3 only on a clash (public link rule). Deleted jobs keep their slug reserved. */
    public static function uniqueSlug(string $text, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::limit($text, 150, '')) ?: 'job';
        $slug = $base;
        for ($i = 2; static::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    /** The job an old slug pointed to (slug changed in admin): SlugRedirect scope `job`. */
    public static function fromOldSlug(string $slug): ?self
    {
        $id = SlugRedirect::query()->where('scope', 'job')->where('old_slug', $slug)->value('target_id');

        return $id ? static::withTrashed()->find($id) : null;
    }
}
