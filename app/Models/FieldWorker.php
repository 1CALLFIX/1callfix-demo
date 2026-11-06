<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The universal field-execution identity — the person who physically does
 * the work, distinct from Provider (the accountable business/individual a
 * job is offered to; see Provider's own docblock). 1:1 with User, same
 * shape as Provider::user_id. A FieldWorker may belong to zero, one, or
 * (schema-wise) several Providers via partner_workers, or exist with no
 * Partner at all for platform-direct dispatch (future Parcel/Taxi).
 *
 * Deliberately NOT built on Provider's dormant provider_type=company /
 * parent_provider_id / technicians() scaffolding — confirmed zero real
 * consumers of that pattern anywhere in the app (Phase B0 audit). That
 * scaffolding stays untouched; this is a parallel, purpose-built table.
 */
class FieldWorker extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'field_workers';

    protected $fillable = [
        'user_id',
        'franchise_id',
        'zone_id',
        'vehicle_type',
        'kyc_status',
        'is_online',
        'current_lat',
        'current_lng',
        'location_updated_at',
        'rating_avg',
        'jobs_completed',
        'is_active',
    ];

    protected $casts = [
        'is_online' => 'boolean',
        'is_active' => 'boolean',
        'location_updated_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }

    /**
     * Owner rule 2026-11-06: one person may be a service partner and a rider on one account, and may take a
     * service job while delivering. But while their service work has started (start code given, status
     * in_progress) they get no delivery offers, so food or a parcel never waits on a job in someone's house.
     */
    public function scopeNotDoingServiceWork($query)
    {
        $busyUsers = Provider::query()
            ->whereNotNull('user_id')
            ->whereIn('id', Booking::query()->where('status', 'in_progress')->whereNotNull('provider_id')->select('provider_id'))
            ->select('user_id');

        return $query->where(fn ($q) => $q->whereNull('user_id')->orWhereNotIn('user_id', $busyUsers));
    }

    public function isDoingServiceWork(): bool
    {
        return $this->user_id !== null && Booking::query()
            ->where('status', 'in_progress')
            ->whereHas('provider', fn ($q) => $q->where('user_id', $this->user_id))
            ->exists();
    }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function capabilities() { return $this->hasMany(FieldWorkerCapability::class); }
    public function documents() { return $this->hasMany(FieldWorkerDocument::class); }
    public function currentDocuments() { return $this->documents()->where('is_current', true); }

    /** Every Partner relationship this worker has, whatever its status — see PartnerWorker::status. */
    public function partnerLinks() { return $this->hasMany(PartnerWorker::class); }

    /** The Providers this worker is actually linked to, through partner_workers — a worker with none is still a valid platform-direct worker. */
    public function partners()
    {
        return $this->belongsToMany(Provider::class, 'partner_workers')
            ->withPivot(['status', 'is_primary'])
            ->withTimestamps();
    }

    public function hasCapability(string $capabilityType): bool
    {
        return $this->capabilities()->where('capability_type', $capabilityType)->exists();
    }
}
