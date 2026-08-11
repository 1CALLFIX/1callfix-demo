<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Setting extends Model
{
    use HasFactory;

    protected $table = 'settings';

    protected $fillable = [
        'scope_type',
        'scope_id',
        'key',
        'value'
    ];

    /**
     * Resolution order when a scope hint is given — most specific first.
     * Zone outranks franchise deliberately: zones.franchise_id makes Zone
     * the child (many zones per franchise), so a zone-level override is
     * more specific than its parent franchise's, not less — fixed here
     * before any caller passes both hints at once (e.g. resolving a
     * setting for a booking, which carries both franchise_id and zone_id).
     */
    private const SCOPE_ORDER = ['zone', 'franchise', 'module', 'city', 'country'];

    /**
     * Global → Country → City → Zone → Module → Franchise cascade (master
     * doc §14). Every existing call site uses the two-argument form
     * (no `$scope`), which resolves to global only — identical behaviour to
     * before this cascade existed, so nothing that already calls
     * Setting::get()/set() needs to change.
     *
     * `$scope` keys are `{level}_id`, e.g. ['franchise_id' => 3, 'zone_id' => 7].
     * Only levels present and non-empty in `$scope` are checked, in
     * SCOPE_ORDER, before falling back to global. Cached forever per
     * resolved key — layouts/admin.blade.php reads a global key on every
     * admin page load — and set() busts just that one key.
     */
    public static function get(string $key, $default = null, array $scope = [])
    {
        $chain = [];
        foreach (self::SCOPE_ORDER as $level) {
            if (! empty($scope["{$level}_id"])) {
                $chain[] = [$level, $scope["{$level}_id"]];
            }
        }
        $chain[] = ['global', null];

        foreach ($chain as [$scopeType, $scopeId]) {
            $value = cache()->rememberForever("setting:{$scopeType}:{$scopeId}:{$key}", fn () =>
                static::where('scope_type', $scopeType)->where('scope_id', $scopeId)->where('key', $key)->value('value')
            );

            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }

    public static function set(string $key, $value, string $scopeType = 'global', ?int $scopeId = null): void
    {
        static::updateOrCreate(['scope_type' => $scopeType, 'scope_id' => $scopeId, 'key' => $key], ['value' => $value]);
        cache()->forget("setting:{$scopeType}:{$scopeId}:{$key}");
    }

    /**
     * Does an override exist at this EXACT scope — no cascading. Backs the
     * Settings screen's "overridden here" vs "inherited" indicator, and
     * clearOverride()'s ability to know whether there's anything to clear.
     */
    public static function existsAt(string $key, string $scopeType, ?int $scopeId): bool
    {
        return static::where('scope_type', $scopeType)->where('scope_id', $scopeId)->where('key', $key)->exists();
    }

    public static function clear(string $key, string $scopeType, ?int $scopeId): void
    {
        static::where('scope_type', $scopeType)->where('scope_id', $scopeId)->where('key', $key)->delete();
        cache()->forget("setting:{$scopeType}:{$scopeId}:{$key}");
    }
}
