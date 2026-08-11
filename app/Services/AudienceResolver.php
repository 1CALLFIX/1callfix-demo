<?php

namespace App\Services;

use App\Models\Franchise;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns a Campaign/Meeting's {recipient_type, scope_type, scope_id, filters}
 * spec into the actual User query — the same real geography/role columns
 * every other screen in this app already uses, not a parallel audience
 * model. Every recipient is a real `users` row; a "provider" recipient is
 * additionally joined through their `providers` profile (franchise_id/
 * zone_id/is_online/subscriptions live there, not on `users`), a
 * "customer" recipient is scoped through their saved addresses (customers
 * have no fixed home franchise/zone column — their addresses do).
 */
class AudienceResolver
{
    public const STAFF_ROLES = ['franchise_owner', 'zone_manager', 'country_admin', 'city_admin', 'operator', 'support', 'super_admin'];

    /**
     * @param  array  $spec  ['recipient_type'=>..., 'specific_user_id'=>?, 'scope_type'=>..., 'scope_id'=>?, 'filters'=>[]]
     */
    public function resolve(array $spec): Builder
    {
        $recipientType = $spec['recipient_type'] ?? 'everyone';
        $filters = $spec['filters'] ?? [];

        if ($recipientType === 'specific_user') {
            return User::query()->where('id', $spec['specific_user_id'] ?? 0);
        }

        // zone scope filters by zone_id directly -- reducing it to "which
        // franchise is this zone in" would wrongly include every OTHER
        // zone of that same franchise too.
        $zoneId = $spec['scope_type'] === 'zone' ? $spec['scope_id'] : null;
        $franchiseIds = $spec['scope_type'] === 'zone'
            ? null
            : $this->franchiseIdsForScope($spec['scope_type'] ?? 'global', $spec['scope_id'] ?? null);

        return match ($recipientType) {
            'customers' => $this->customers($franchiseIds, $zoneId, $spec['module'] ?? null, $filters),
            'providers' => $this->providers($franchiseIds, $zoneId, $spec['module'] ?? null, $filters),
            'staff' => $this->staff($franchiseIds, $zoneId, $filters),
            default => $this->everyone($franchiseIds, $zoneId, $filters), // 'everyone'
        };
    }

    /**
     * Null = no scope restriction (global). A scope that resolves to zero
     * franchises (e.g. a city with no franchises yet) correctly yields an
     * empty recipient list rather than silently falling back to everyone.
     * (Zone is handled separately in resolve() above, never reaches here.)
     */
    private function franchiseIdsForScope(string $scopeType, ?int $scopeId): ?array
    {
        return match ($scopeType) {
            'country' => Franchise::where('country_id', $scopeId)->pluck('id')->all(),
            'city' => Franchise::where('city_id', $scopeId)->pluck('id')->all(),
            'franchise' => [$scopeId],
            default => null, // global
        };
    }

    private function customers(?array $franchiseIds, ?int $zoneId, ?string $module, array $filters): Builder
    {
        $query = User::query()->where('role', 'customer');

        if ($zoneId !== null) {
            $query->whereHas('addresses', fn ($q) => $q->where('zone_id', $zoneId));
        } elseif ($franchiseIds !== null) {
            $query->whereHas('addresses', fn ($q) => $q->whereIn('franchise_id', $franchiseIds));
        }

        if (! empty($filters['active_only'])) {
            $query->where('status', 'active');
        }

        if (! empty($filters['prime_only'])) {
            $query->whereHas('protectionPlans', fn ($q) => $q->where('status', 'active'));
        }

        return $query;
    }

    private function providers(?array $franchiseIds, ?int $zoneId, ?string $module, array $filters): Builder
    {
        $query = User::query()->where('role', 'provider')
            ->whereHas('providerProfile', function ($q) use ($franchiseIds, $zoneId, $module, $filters) {
                if ($zoneId !== null) {
                    $q->where('zone_id', $zoneId);
                } elseif ($franchiseIds !== null) {
                    $q->whereIn('franchise_id', $franchiseIds);
                }
                if ($module) {
                    $q->whereHas('franchise.modules', fn ($m) => $m->where($module, true));
                }
                if (! empty($filters['active_only'])) {
                    $q->where('is_active', true);
                }
                if (! empty($filters['online_only'])) {
                    $q->where('is_online', true);
                }
                if (! empty($filters['subscription_active'])) {
                    $q->whereHas('subscriptions', fn ($s) => $s->where('status', 'successful')->where('expires_at', '>', now()));
                }
            });

        return $query;
    }

    private function staff(?array $franchiseIds, ?int $zoneId, array $filters): Builder
    {
        $query = User::query()->whereIn('role', self::STAFF_ROLES);

        if ($zoneId !== null) {
            $query->where('zone_id', $zoneId);
        } elseif ($franchiseIds !== null) {
            $query->whereIn('franchise_id', $franchiseIds);
        }

        if (! empty($filters['active_only'])) {
            $query->where('status', 'active');
        }

        return $query;
    }

    private function everyone(?array $franchiseIds, ?int $zoneId, array $filters): Builder
    {
        $query = User::query();

        // Three different places geography lives depending on WHO the user
        // is: users.zone_id/franchise_id directly (staff, and franchise_owner
        // via ownedFranchises -- covered by users.franchise_id), the
        // providers row (providers.zone_id/franchise_id -- a provider's own
        // users.zone_id is typically unset), or the customer's saved
        // addresses. "Everyone" has to check all three, or providers/
        // customers silently vanish from a scoped all-audience broadcast.
        if ($zoneId !== null) {
            $query->where(function ($q) use ($zoneId) {
                $q->where('zone_id', $zoneId)
                    ->orWhereHas('addresses', fn ($a) => $a->where('zone_id', $zoneId))
                    ->orWhereHas('providerProfile', fn ($p) => $p->where('zone_id', $zoneId));
            });
        } elseif ($franchiseIds !== null) {
            $query->where(function ($q) use ($franchiseIds) {
                $q->whereIn('franchise_id', $franchiseIds)
                    ->orWhereHas('addresses', fn ($a) => $a->whereIn('franchise_id', $franchiseIds))
                    ->orWhereHas('providerProfile', fn ($p) => $p->whereIn('franchise_id', $franchiseIds));
            });
        }

        if (! empty($filters['active_only'])) {
            $query->where('status', 'active');
        }

        return $query;
    }
}
