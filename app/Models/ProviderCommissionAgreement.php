<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A negotiated platform commission rate for one specific Provider — tier 1
 * of ProviderCommercialRateResolver's three-tier hierarchy. One row per
 * provider (unique provider_id); the row's existence is itself the "this
 * provider has a negotiated rate" signal — see the creating migration's
 * docblock. Written/removed via SetProviderCommissionAgreementAction only.
 */
class ProviderCommissionAgreement extends Model
{
    use HasFactory;

    protected $table = 'provider_commission_agreements';

    protected $fillable = [
        'provider_id',
        'platform_fee_percent',
        'notes',
        'set_by_user_id',
    ];

    public function provider() { return $this->belongsTo(Provider::class); }
    public function setBy() { return $this->belongsTo(User::class, 'set_by_user_id'); }
}
