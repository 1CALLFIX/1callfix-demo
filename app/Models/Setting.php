<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Setting extends Model
{
    use HasFactory;

    protected $table = 'settings';

    protected $fillable = [
        'franchise_id',
        'key',
        'value'
    ];
    public function franchise() { return $this->belongsTo(Franchise::class); }

    /**
     * Global settings only (franchise_id IS NULL) — the column stays nullable
     * for a future franchise-override cascade (Master Context doc §14), but
     * nothing builds that cascade yet, so every read/write here is global.
     *
     * Cached forever per key since this is read from layouts/admin.blade.php
     * on every admin page load; set() busts just that key.
     */
    public static function get(string $key, $default = null)
    {
        $value = cache()->rememberForever("setting:{$key}", fn () =>
            static::whereNull('franchise_id')->where('key', $key)->value('value')
        );

        return $value ?? $default;
    }

    public static function set(string $key, $value): void
    {
        static::updateOrCreate(['franchise_id' => null, 'key' => $key], ['value' => $value]);
        cache()->forget("setting:{$key}");
    }
}
