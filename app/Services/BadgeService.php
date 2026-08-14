<?php

namespace App\Services;

use App\Models\Badge;
use App\Models\BadgeAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Universal Badge Engine — one implementation for every entity type
 * (Service today, any future one without a schema change) and every badge
 * mode. See create_badges_table/create_badge_assignments_table's own
 * migration docblocks for the full manual/automatic design rationale.
 */
class BadgeService
{
    /**
     * Every currently-applicable badge for one entity, in display order
     * (highest priority first) — the shape a customer-facing serializer
     * (API resource, Blade partial) reads directly. Combines:
     *  - persisted MANUAL assignments, filtered to currently-visible
     *    (active + within their own time window) AND scope-covering
     *    $viewerScope (global always applies; a zone-scoped assignment
     *    only applies to a viewer resolved to that same zone/franchise/
     *    city/country ancestry).
     *  - AUTOMATIC badges, evaluated live against the entity's own
     *    attributes every call — never persisted, so there's nothing to
     *    keep in sync and no cron dependency for correctness.
     *
     * @param  array  $viewerScope  e.g. ['zone_id' => 5, 'franchise_id' => 3, 'city_id' => 2, 'country_id' => 1] — the customer's own resolved location. Empty array = only global-scoped badges apply.
     */
    public function badgesFor(Model $entity, array $viewerScope = []): array
    {
        $authz = app(AuthorizationService::class);

        $manual = BadgeAssignment::currentlyVisible()
            ->where('badgeable_type', get_class($entity))
            ->where('badgeable_id', $entity->getKey())
            ->with('badge')
            ->get()
            ->filter(fn (BadgeAssignment $a) => $a->badge && $a->badge->is_active)
            ->filter(fn (BadgeAssignment $a) => $authz->scopeCovers($a->scope_type, $a->scope_id, $viewerScope))
            ->map(fn (BadgeAssignment $a) => $a->badge);

        $automatic = Badge::where('mode', 'automatic')->where('is_active', true)->get()
            ->filter(fn (Badge $badge) => $this->automaticRuleMatches($badge, $entity));

        return $manual->concat($automatic)
            ->unique('id')
            ->sortByDesc('priority')
            ->map(fn (Badge $badge) => $badge->toDisplayArray())
            ->values()
            ->all();
    }

    /**
     * Every rule_type this session actually implements, honestly — only
     * 'recently_created' (what NEW needs). A badge with an unrecognized
     * rule_type simply never matches rather than guessing what it might
     * have meant; adding a new rule type is additive here, never a
     * migration change (rule_config already supports arbitrary JSON).
     */
    private function automaticRuleMatches(Badge $badge, Model $entity): bool
    {
        return match ($badge->rule_type) {
            'recently_created' => $this->matchesRecentlyCreated($badge, $entity),
            default => false,
        };
    }

    private function matchesRecentlyCreated(Badge $badge, Model $entity): bool
    {
        if (! $entity->created_at) {
            return false;
        }

        $withinDays = (int) ($badge->rule_config['within_days'] ?? 14);

        return $entity->created_at->greaterThan(Carbon::now()->subDays($withinDays));
    }

    /**
     * @throws \RuntimeException if an active, currently-visible assignment
     *         of this exact badge already exists for this entity at this
     *         exact scope — checked at the service layer (not a blind DB
     *         unique constraint) so expired/revoked history rows never
     *         block a fresh assignment, the same "check before insert"
     *         style ReferralService::createFromSignup() already uses for
     *         its own duplicate-prevention.
     */
    public function assign(Badge $badge, Model $entity, string $scopeType = 'global', ?int $scopeId = null, ?Carbon $expiresAt = null, ?User $assignedBy = null): BadgeAssignment
    {
        if ($badge->isAutomatic()) {
            throw new \RuntimeException("Badge [{$badge->key}] is automatic — it cannot be manually assigned.");
        }

        $duplicate = BadgeAssignment::currentlyVisible()
            ->where('badge_id', $badge->id)
            ->where('badgeable_type', get_class($entity))
            ->where('badgeable_id', $entity->getKey())
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->exists();

        if ($duplicate) {
            throw new \RuntimeException("This entity already carries the [{$badge->key}] badge at this scope.");
        }

        if (! $expiresAt && $badge->default_duration_days) {
            $expiresAt = Carbon::now()->addDays($badge->default_duration_days);
        }

        return BadgeAssignment::create([
            'badge_id' => $badge->id,
            'badgeable_type' => get_class($entity),
            'badgeable_id' => $entity->getKey(),
            'scope_type' => $scopeType,
            'scope_id' => $scopeType === 'global' ? null : $scopeId,
            'starts_at' => now(),
            'expires_at' => $expiresAt,
            'is_active' => true,
            'assigned_by' => $assignedBy?->id,
        ]);
    }

    /** Deactivates, never deletes — the assignment stays as an audit row (who assigned it, when, and now when it was revoked via updated_at). */
    public function revoke(BadgeAssignment $assignment): void
    {
        $assignment->update(['is_active' => false]);
    }
}
