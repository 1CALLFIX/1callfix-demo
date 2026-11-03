<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A change to a live item or shop waiting for admin approval (owner rule 2026-11-03). The item / shop stays live with
 * its old details until an admin approves; a rejection changes nothing. See App\Services\PendingChangeService.
 */
class PendingChange extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const REPLACED = 'replaced';

    /** Shown in plain words to the seller and the admin. */
    public const LABELS = [
        'name' => 'Name', 'description' => 'Description', 'images' => 'Photos', 'marketplace_category_id' => 'Category',
        'address_line' => 'Address', 'lat' => 'Map pin', 'lng' => 'Map pin', 'gstin' => 'GSTIN', 'pan' => 'PAN', 'fssai_number' => 'FSSAI number',
    ];

    protected $fillable = [
        'changeable_type', 'changeable_id', 'store_id', 'requested_by', 'new_values', 'old_values', 'status',
        'review_note', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'new_values' => 'array',
        'old_values' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function changeable()
    {
        return $this->morphTo();
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::PENDING);
    }

    /** "Name, Photos" — what this change touches, in plain words. */
    public function summary(): string
    {
        return collect(array_keys($this->new_values ?? []))->map(fn ($f) => self::LABELS[$f] ?? $f)->unique()->implode(', ');
    }
}
