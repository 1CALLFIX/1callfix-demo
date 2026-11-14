<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Houses for rent: a customer asks to see a home on a day and time; the owner accepts or declines. */
class HouseVisit extends Model
{
    public const STATUS_LABELS = [
        'requested' => 'Waiting for the owner', 'accepted' => 'Visit accepted', 'declined' => 'Owner said no',
        'cancelled' => 'Cancelled', 'expired' => 'No reply in time',
    ];

    /** Still asking or agreed: counts against the customer's open requests for the same home. */
    public const OPEN = ['requested', 'accepted'];

    protected $fillable = [
        'code', 'house_listing_id', 'customer_id', 'franchise_id', 'zone_id', 'visit_date', 'slot', 'customer_name',
        'customer_phone', 'tenant_type', 'message', 'status', 'owner_note', 'accepted_at', 'declined_at', 'cancelled_at',
    ];

    protected $casts = [
        'visit_date' => 'date',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function listing() { return $this->belongsTo(HouseListing::class, 'house_listing_id'); }
    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? Str::headline((string) $this->status);
    }

    public static function newCode(): string
    {
        do {
            $code = 'HV-'.strtoupper(Str::random(6));
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
