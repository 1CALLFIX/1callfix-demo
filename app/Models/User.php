<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'uuid',
        'name',
        'phone',
        'email',
        'password',
        'role',
        'franchise_id',
        'zone_id',
        'profile_photo',
        'preferred_language',
        'status',
        'phone_verified_at',
        'email_verified_at',
        'firebase_uid',
        'google_id',
        'avatar_url',
        'fcm_token',
        'push_ops_alerts',
        // REF 1CF-PROMPT-20260925-EARN4 (R1) — referral_code and referred_by
        // are deliberately NOT mass-assignable: a referral is fixed at account
        // creation (CustomerAccountResolver, forceFill) and no request payload
        // can attach or change one afterwards. Same for the referral block and
        // signup/login fingerprint columns (Super Admin / auth code only).
    ];

    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['phone_verified_at' => 'datetime', 'email_verified_at' => 'datetime', 'push_ops_alerts' => 'boolean',
        'referral_blocked_at' => 'datetime', 'last_login_at' => 'datetime'];
    public function referrer() { return $this->belongsTo(User::class, 'referred_by'); }
    public function referralBlockedBy() { return $this->belongsTo(User::class, 'referral_blocked_by'); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function providerProfile() { return $this->hasOne(Provider::class); }
    /** The universal field-execution profile — distinct from providerProfile(). A User may hold either, both, or neither depending on their real role. */
    public function fieldWorkerProfile() { return $this->hasOne(FieldWorker::class); }
    /** An active customer with no partner profile yet may apply on the partner sign-up with this same account (owner 2026-10-27). */
    public function canApplyAsPartner(): bool
    {
        return $this->role === 'customer' && $this->status === 'active'
            && ! $this->providerProfile()->exists() && ! $this->fieldWorkerProfile()->exists();
    }
    public function addresses() { return $this->hasMany(Address::class); }
    public function bookings() { return $this->hasMany(Booking::class, 'customer_id'); }
    public function wallet() { return $this->hasOne(Wallet::class); }
    public function ownedFranchises() { return $this->hasMany(Franchise::class, 'owner_user_id'); }
    public function protectionPlans() { return $this->hasMany(UserProtectionPlan::class); }
    public function roleAssignments() { return $this->hasMany(RoleAssignment::class); }
    public function loyaltyPoints() { return $this->hasMany(LoyaltyPoint::class); }
    public function referralsMade() { return $this->hasMany(Referral::class, 'referrer_id'); }
    public function referralReceived() { return $this->hasOne(Referral::class, 'referred_id'); }
    /** Plan Engine subscriptions (Customer Prime / Provider Package) — named distinctly from Provider::subscriptions() (the old provider_subscriptions table). */
    public function planSubscriptions() { return $this->morphMany(\App\Models\Subscription::class, 'subscribable'); }

    public function routeNotificationForSms(): ?string { return $this->phone; }
    public function routeNotificationForPush(): ?string { return $this->fcm_token; }

    /** @see \App\Services\AuthorizationService::can() */
    public function hasPermission(string $permission, array $scope = []): bool
    {
        return app(\App\Services\AuthorizationService::class)->can($this, $permission, $scope);
    }

    /** @see \App\Services\AuthorizationService::canAnywhere() */
    public function hasPermissionAnywhere(string $permission): bool
    {
        return app(\App\Services\AuthorizationService::class)->canAnywhere($this, $permission);
    }
}