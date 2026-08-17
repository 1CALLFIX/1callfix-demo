<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Provider extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'providers';

    protected $fillable = [
        'user_id',
        'franchise_id',
        'zone_id',
        'provider_type',
        'parent_provider_id',
        'skills',
        'kyc_status',
        'kyc_deadline_at',
        'kyc_reminder_stage',
        'kyc_video_status',
        'credit_balance',
        'is_online',
        'current_lat',
        'current_lng',
        'location_updated_at',
        'rating_avg',
        'priority',
        'jobs_completed',
        'is_active'
    ];

    protected $casts = ['skills' => 'array', 'is_online' => 'boolean', 'location_updated_at' => 'datetime', 'kyc_deadline_at' => 'datetime'];
    public function user() { return $this->belongsTo(User::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function parent() { return $this->belongsTo(Provider::class, 'parent_provider_id'); }
    public function technicians() { return $this->hasMany(Provider::class, 'parent_provider_id'); }
    public function documents() { return $this->hasMany(ProviderDocument::class); }
    public function currentDocuments() { return $this->documents()->where('is_current', true); }
    public function verificationVideos() { return $this->hasMany(KycVerificationVideo::class); }
    public function latestVerificationVideo() { return $this->hasOne(KycVerificationVideo::class)->latestOfMany(); }
    public function withdrawalExceptions() { return $this->hasMany(KycWithdrawalException::class); }
    public function kycSupportRequests() { return $this->hasMany(KycSupportRequest::class); }
    public function subscriptions() { return $this->hasMany(ProviderSubscription::class); }
    public function bookings() { return $this->hasMany(Booking::class); }
    public function reviews() { return $this->hasMany(Review::class); }
    /** Phase 24 (Marketplace Foundation) -- a Provider may own several Store rows (multi-location), same identity-reuse pattern as properties(). */
    public function stores() { return $this->hasMany(Store::class); }

    /** Every Partner<->Worker link this Provider has, whatever its status — see PartnerWorker::status. Phase B0.1 foundation; nothing yet consumes this for real assignment. */
    public function partnerWorkerLinks() { return $this->hasMany(PartnerWorker::class); }
    /** The FieldWorkers actually linked to this Provider, through partner_workers. */
    public function workers()
    {
        return $this->belongsToMany(FieldWorker::class, 'partner_workers')
            ->withPivot(['status', 'is_primary'])
            ->withTimestamps();
    }
}
