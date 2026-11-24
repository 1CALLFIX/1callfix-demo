<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Field associates release A (owner 2026-11-24): one line of a shop's credit history: credited at sign-up, added by an
 * admin, moved to another associate, or removed. A reason is always saved; two associates can never hold the same
 * shop (stores.field_associate_id is one column).
 */
class FieldAssociateCreditChange extends Model
{
    protected $fillable = ['store_id', 'from_associate_id', 'to_associate_id', 'changed_by', 'reason'];

    public function from(): BelongsTo
    {
        return $this->belongsTo(FieldAssociate::class, 'from_associate_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(FieldAssociate::class, 'to_associate_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
