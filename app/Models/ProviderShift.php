<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A named working window ("Morning 08:00–14:00") the admin offers and providers choose from. */
class ProviderShift extends Model
{
    protected $fillable = ['name', 'start_time', 'end_time', 'days', 'is_active', 'sort_order'];

    protected $casts = ['days' => 'array', 'is_active' => 'boolean'];

    public const DAY_NAMES = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];

    public function providers(): BelongsToMany
    {
        return $this->belongsToMany(Provider::class, 'provider_shift_selections')->withTimestamps();
    }

    /** "Mon–Sat" style label, or "Every day". */
    public function daysLabel(): string
    {
        $days = collect($this->days ?? [])->map(fn ($d) => (int) $d)->unique()->sort()->values();

        if ($days->isEmpty() || $days->count() === 7) {
            return 'Every day';
        }

        return $days->map(fn ($d) => self::DAY_NAMES[$d] ?? '')->implode(', ');
    }
}
