<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KycSupportRequest extends Model
{
    protected $table = 'kyc_support_requests';

    protected $fillable = [
        'provider_id', 'franchise_id', 'raised_by', 'reason', 'missing_documents',
        'assistance_provided', 'urgency', 'status', 'decided_by', 'decided_at',
        'decision_note', 'exception_id',
    ];

    protected $casts = ['missing_documents' => 'array', 'decided_at' => 'datetime'];

    public function provider() { return $this->belongsTo(Provider::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function raisedBy() { return $this->belongsTo(User::class, 'raised_by'); }
    public function decidedBy() { return $this->belongsTo(User::class, 'decided_by'); }
    public function exception() { return $this->belongsTo(KycWithdrawalException::class, 'exception_id'); }

    /** Ancestor-inclusive scope hint — franchise-owned, so a scoped admin/franchise actor sees only their own franchise's requests. */
    public function authorizationScopeHint(): array
    {
        return app(\App\Services\AuthorizationService::class)->ancestryFor('franchise', $this->franchise_id);
    }
}
