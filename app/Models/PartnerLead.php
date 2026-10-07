<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A person who applied on the public /partners page (REF 1CF-PARTNER-PAGE-001). One row per (phone, role).
 * Status is never taken from the client: the server sets it from the role's registry state.
 */
class PartnerLead extends Model
{
    public const STATUS_NEW = 'new';
    public const STATUS_WAITLIST = 'waitlist';
    public const STATUS_HANDED_OFF = 'handed_off';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_NEW, self::STATUS_WAITLIST, self::STATUS_HANDED_OFF, self::STATUS_CONVERTED, self::STATUS_REJECTED];

    /** A repeat submit never reopens these. */
    private const FINAL = [self::STATUS_CONVERTED, self::STATUS_REJECTED];

    protected $fillable = [
        'name', 'phone', 'city', 'role', 'status', 'consent_at', 'consent_text', 'acquisition', 'source', 'provider_id', 'submit_count',
    ];

    protected $casts = [
        'acquisition' => 'array',
        'consent_at' => 'datetime',
        'submit_count' => 'integer',
    ];

    /**
     * Create the lead, or update the existing (phone, role) row. Safe against two simultaneous submits: the unique
     * index decides, and the loser re-reads and updates.
     *
     * @param  array{name:string, phone:string, city:string, role:string, status:string, consent_text:?string, acquisition:?array, source:?string}  $d
     */
    public static function capture(array $d): self
    {
        $attempt = function () use ($d): self {
            return DB::transaction(function () use ($d) {
                $lead = static::where('phone', $d['phone'])->where('role', $d['role'])->lockForUpdate()->first();

                if ($lead === null) {
                    return static::create($d + ['consent_at' => now(), 'submit_count' => 1]);
                }

                $lead->fill([
                    'name' => $d['name'], 'city' => $d['city'], 'consent_at' => now(), 'consent_text' => $d['consent_text'],
                    // First touch wins: never overwrite an earlier attribution record.
                    'acquisition' => $lead->acquisition ?: $d['acquisition'],
                    'status' => in_array($lead->status, self::FINAL, true) ? $lead->status : $d['status'],
                ]);
                $lead->submit_count = $lead->submit_count + 1;
                $lead->save();

                return $lead;
            });
        };

        try {
            return $attempt();
        } catch (QueryException $e) {
            // Lost the race on the unique (phone, role) index: the row exists now, so update it.
            return $attempt();
        }
    }

    /** Provider sign-up finished: link only when the VERIFIED phone matches the lead's phone and the role is service. */
    public static function markConverted(?int $leadId, string $verifiedNationalPhone, int $providerId): void
    {
        if (! $leadId) {
            return;
        }

        static::where('id', $leadId)->where('phone', $verifiedNationalPhone)->where('role', 'service')
            ->whereNotIn('status', [self::STATUS_REJECTED])
            ->update(['status' => self::STATUS_CONVERTED, 'provider_id' => $providerId, 'updated_at' => now()]);
    }
}
