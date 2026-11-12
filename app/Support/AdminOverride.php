<?php

namespace App\Support;

use App\Models\User;
use App\Services\ActivityLogger;

/**
 * Owner 2026-11-12: emergency override. When a provider, rider, shop or restaurant cannot update a job or order
 * themselves (phone dead, app down, no signal), Super Admin or a franchise admin with the
 * `orders.emergency_override` permission (scoped to their own franchise) moves it on their behalf.
 * A written reason is required and every override is recorded in the order's history (who + reason) and the
 * activity log. The existing lifecycle actions do the work, so payments, commission and notifications run exactly
 * as if the partner had done it; only the partner's OTP / "is it yours" check is skipped.
 */
final class AdminOverride
{
    public const PERMISSION = 'orders.emergency_override';

    private function __construct(public readonly User $by, public readonly string $reason)
    {
    }

    /** @throws \RuntimeException when the reason is missing or too short */
    public static function make(User $by, ?string $reason): self
    {
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 5) {
            throw new \RuntimeException('Write the reason for this emergency change (at least 5 letters).');
        }

        return new self($by, mb_substr($reason, 0, 500));
    }

    /** History note, e.g. "Marked delivered by admin Ravi (emergency): rider's phone is dead". */
    public function note(string $what): string
    {
        return mb_substr("{$what} by admin ".($this->by->name ?: "#{$this->by->id}")." (emergency): {$this->reason}", 0, 170);
    }

    /** Activity log entry, so every override can be found in one place. */
    public function log(string $subjectType, int $subjectId, string $what, array $extra = []): void
    {
        ActivityLogger::log($this->by, $subjectType, $subjectId, "Emergency override: {$what}", ['reason' => $this->reason] + $extra);
    }
}
