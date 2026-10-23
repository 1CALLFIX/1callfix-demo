<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** REF 1CF-BACKUP-RESTORE-001 — one "Restore one" comparison / restore (see ScopedRestoreService). */
class DataRestore extends Model
{
    public const PREPARING = 'preparing';

    public const READY = 'ready';

    public const RESTORING = 'restoring';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $fillable = [
        'scope_type', 'scope_id', 'scope_label', 'backup_id', 'backup_at', 'status', 'summary', 'result',
        'error', 'reason', 'requested_by', 'executed_by', 'safety_copy_path', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'backup_at' => 'datetime',
            'finished_at' => 'datetime',
            'summary' => 'array',
            'result' => 'array',
        ];
    }

    public function isBusy(): bool
    {
        return in_array($this->status, [self::PREPARING, self::RESTORING], true);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
