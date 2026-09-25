<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * REF 1CF-PROMPT-20260925-EARN3 (Rule of Law #5) — the audit trail is
 * APPEND-ONLY. Updating or deleting a row throws, whether through a model
 * instance (updating/deleting events) or a query (the builder below overrides
 * update()/delete()/forceDelete()/truncate()). Only a raw DB::table() call
 * could bypass this. Operations → Clear Data never touches activity_log
 * (DataClearCatalog::NEVER_CLEARABLE), and QaCleaner doesn't either; the only
 * database-level change is users FK nullOnDelete of causer_id, applied by the
 * database itself when a user is removed.
 */
class ActivityLog extends Model
{
    use HasFactory;

    protected $table = 'activity_log';

    protected $fillable = [
        'causer_id',
        'subject_type',
        'subject_id',
        'description',
        'properties'
    ];
    protected $casts = ['properties' => 'array'];
    public function causer() { return $this->belongsTo(User::class, 'causer_id'); }

    /**
     * REF 1CF-PROMPT-20260925-QA3 — the one-line human detail of an audit
     * entry, shown under its description on Operations: a setting change reads
     * "wallet.customer_min_topup (global): 100 → 150" (UNSET for a null side),
     * a money / referral action shows its mandatory reason.
     */
    public function auditDetail(): ?string
    {
        $p = is_array($this->properties) ? $this->properties : [];

        if (isset($p['key']) && is_string($p['key'])) {
            $side = fn ($v) => is_scalar($v) ? (string) $v : 'UNSET';
            $scope = ($p['scope_type'] ?? 'global').(! empty($p['scope_id']) ? ' #'.$p['scope_id'] : '');

            return "{$p['key']} ({$scope}): {$side($p['old'] ?? null)} → {$side($p['new'] ?? null)}";
        }

        if (isset($p['reason']) && is_scalar($p['reason'])) {
            return 'Reason: '.$p['reason'];
        }

        return null;
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('activity_log is append-only: rows cannot be updated.'));
        static::deleting(fn () => throw new \LogicException('activity_log is append-only: rows cannot be deleted.'));
    }

    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            public function update(array $values)
            {
                throw new \LogicException('activity_log is append-only: rows cannot be updated.');
            }

            public function delete()
            {
                throw new \LogicException('activity_log is append-only: rows cannot be deleted.');
            }

            public function forceDelete()
            {
                throw new \LogicException('activity_log is append-only: rows cannot be deleted.');
            }

            public function truncate()
            {
                throw new \LogicException('activity_log is append-only: rows cannot be deleted.');
            }
        };
    }
}
