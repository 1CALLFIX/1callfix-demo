<?php

namespace App\Support;

use App\Models\FieldWorker;
use App\Models\Provider;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The Needs review list on Admin → Partners. A partner waits from their oldest current pending document; the wait
 * turns amber after SiteControls::reviewAmberHours() and Overdue (red) after reviewRedHours() — both Super Admin
 * settings (Admin → Homepage & sign-up). Every count is limited to the user's own area.
 */
final class PartnerReview
{
    /** kyc_status values that mean "documents sent, nobody has decided yet". */
    public const STATUSES = ['pending', 'submitted', 'under_review'];

    /** Partners tab => [model, view permission]. */
    public const GROUPS = [
        'people' => [FieldWorker::class, 'workers.view'],
        'businesses' => [Provider::class, 'providers.view'],
    ];

    private const SCOPE_COLUMNS = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

    /** Whole hours waited since $since (a list row's waiting_since), or null when nothing is waiting. */
    public static function hours($since): ?int
    {
        return $since ? (int) Carbon::parse($since)->diffInHours(now()) : null;
    }

    /** 'red' (Overdue), 'amber' or null for a wait of $hours. */
    public static function level(?int $hours): ?string
    {
        return match (true) {
            $hours === null => null,
            $hours >= SiteControls::reviewRedHours() => 'red',
            $hours >= SiteControls::reviewAmberHours() => 'amber',
            default => null,
        };
    }

    /** Narrows a partner query to the Overdue ones: still waiting, with a current pending document older than the red hours. */
    public static function overdue(Builder $query): Builder
    {
        $cutoff = now()->subHours(SiteControls::reviewRedHours());

        return $query->whereIn('kyc_status', self::STATUSES)
            ->whereHas('documents', fn ($q) => $q->where('is_current', true)->where('status', 'pending')->where('created_at', '<=', $cutoff));
    }

    /** Waiting and Overdue counts per Partners tab the user may open, inside their own area. */
    public static function countsFor(User $user): array
    {
        return array_map(fn ($scoped) => [
            'waiting' => $scoped()->whereIn('kyc_status', self::STATUSES)->count(),
            'overdue' => self::overdue($scoped())->count(),
        ], self::scopedQueries($user));
    }

    /** Overdue partners across every tab the user may open — the red dot on Partners in the admin menu. */
    public static function overdueFor(User $user): int
    {
        return array_sum(array_map(fn ($scoped) => self::overdue($scoped())->count(), self::scopedQueries($user)));
    }

    /** Tab => fresh query factory, limited to the user's own area, for each tab the user may open. */
    private static function scopedQueries(User $user): array
    {
        $auth = app(AuthorizationService::class);
        $queries = [];
        foreach (self::GROUPS as $key => [$model, $permission]) {
            if ($user->hasPermissionAnywhere($permission)) {
                $queries[$key] = fn () => $auth->scopeQuery($model::query(), $user, $permission, self::SCOPE_COLUMNS);
            }
        }

        return $queries;
    }
}
