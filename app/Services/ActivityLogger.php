<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * `activity_log` has existed since Phase P3 but had ZERO writers anywhere
 * in the codebase (confirmed by search before building this) -- a real,
 * unused audit trail. Wired in this session into every Operations
 * mutation (failed-job retry/discard, webhook reprocess, stuck-booking
 * recovery links) per the mission's own "ensure sensitive operational
 * actions are auditable" instruction. Deliberately NOT retrofitted across
 * every historical action in the codebase -- that's a much larger,
 * separately-scoped effort; this session wires it into the Operations
 * domain specifically, which is what Phase 10 asked for.
 */
class ActivityLogger
{
    public static function log(?User $causer, string $subjectType, int $subjectId, string $description, array $properties = []): ActivityLog
    {
        return ActivityLog::create([
            'causer_id' => $causer?->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => $description,
            'properties' => $properties,
        ]);
    }

    public static function logModel(?User $causer, Model $subject, string $description, array $properties = []): ActivityLog
    {
        return self::log($causer, get_class($subject), $subject->getKey(), $description, $properties);
    }
}
