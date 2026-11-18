<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Rent anything: one person's report on an item (one per person per item). The team dismisses or upholds it. */
class RentalItemReport extends Model
{
    /** Tap-to-pick reasons a customer can give. */
    public const REASONS = [
        'not_allowed' => 'Item not allowed',
        'scam' => 'Looks fake or a scam',
        'wrong_details' => 'Wrong price, photos or details',
        'not_available' => 'Owner says it is not available',
        'other' => 'Something else',
    ];

    protected $fillable = ['rental_item_id', 'user_id', 'reason', 'note', 'status', 'handled_by', 'handled_at'];

    protected $casts = ['handled_at' => 'datetime'];

    public function item() { return $this->belongsTo(RentalItem::class, 'rental_item_id'); }
    public function user() { return $this->belongsTo(User::class); }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }
}
