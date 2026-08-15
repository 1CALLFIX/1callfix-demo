<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Payout extends Model
{
    use HasFactory;

    protected $table = 'payouts';

    protected $fillable = [
        'payee_type',
        'payee_id',
        'payment_account_id',
        'amount',
        'period_start',
        'period_end',
        'status',
        'gateway_ref',
        'processed_at'
    ];
    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'processed_at' => 'datetime'];

    public function paymentAccount() { return $this->belongsTo(PaymentAccount::class); }

    /**
     * Phase 21 item TECH-1 (row-level franchise scope). `payee_type`/
     * `payee_id` is a manual type discriminator, not a real Eloquent
     * relation (see PayoutService::resolvePayeeUser()) -- Payout can't be
     * scoped via AuthorizationService::scopeQuery()'s generic
     * relation-traversal the way Booking/Commission-derived screens are.
     * It DOES carry the same shape Plan/NotificationCampaign already solve
     * this for: a single resolvable scope hint per row, not separate
     * zone_id/franchise_id columns -- so this reuses
     * AuthorizationService::visibleAmong()'s existing convention
     * (see Plan::authorizationScopeHint()) rather than inventing a new one.
     * Delegates to PayoutService::payoutScope(), the same resolution
     * Settings lookups (min/max payout amount) already use -- one place
     * computes "which franchise does this payout belong to," not two.
     */
    public function authorizationScopeHint(): array
    {
        return app(\App\Services\PayoutService::class)->payoutScope($this->payee_type, $this->payee_id);
    }
}
