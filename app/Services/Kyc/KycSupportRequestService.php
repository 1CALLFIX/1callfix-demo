<?php

namespace App\Services\Kyc;

use App\Models\Franchise;
use App\Models\KycSupportRequest;
use App\Models\KycWithdrawalException;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "KYC / Withdrawal Restriction Support Request" — the ONLY path a
 * Franchise has to influence a Partner's withdrawal restriction (mission
 * Phase 4). Franchise staff create(); only a Central Admin holding the
 * SEPARATE kyc.support_requests.decide permission can decide() — checked at
 * the Livewire layer, same separation-of-duties pattern as
 * PerformanceCampaignService's approve step. Approval never flips a boolean
 * — it creates an auditable, scoped, optionally time-bound
 * KycWithdrawalException row (see KycWithdrawalPolicyService).
 */
class KycSupportRequestService
{
    public function create(
        Provider $provider,
        Franchise $franchise,
        User $raisedBy,
        string $reason,
        array $missingDocuments = [],
        ?string $assistanceProvided = null,
        string $urgency = 'normal',
    ): KycSupportRequest {
        return KycSupportRequest::create([
            'provider_id' => $provider->id,
            'franchise_id' => $franchise->id,
            'raised_by' => $raisedBy->id,
            'reason' => $reason,
            'missing_documents' => $missingDocuments,
            'assistance_provided' => $assistanceProvided,
            'urgency' => in_array($urgency, ['low', 'normal', 'high'], true) ? $urgency : 'normal',
            'status' => 'open',
        ]);
    }

    /**
     * @param  string  $outcome  'approved'|'rejected'|'more_info_requested'
     * @param  int|null  $exceptionDays  null = exception never expires on its own (still revocable); a positive int = time-bound.
     */
    public function decide(KycSupportRequest $request, User $decider, string $outcome, ?string $note = null, ?int $exceptionDays = null): KycSupportRequest
    {
        if (! in_array($outcome, ['approved', 'rejected', 'more_info_requested'], true)) {
            throw new \InvalidArgumentException("Unknown outcome [{$outcome}].");
        }

        if (in_array($request->status, ['approved', 'rejected', 'closed'], true)) {
            throw new \RuntimeException("This support request is already {$request->status} and cannot be decided again.");
        }

        return DB::transaction(function () use ($request, $decider, $outcome, $note, $exceptionDays) {
            $exceptionId = null;

            if ($outcome === 'approved') {
                $exception = KycWithdrawalException::create([
                    'provider_id' => $request->provider_id,
                    'granted_by' => $decider->id,
                    'reason' => $note ?: "Approved via support request #{$request->id}",
                    'starts_at' => now(),
                    'expires_at' => $exceptionDays ? now()->addDays($exceptionDays) : null,
                ]);
                $exceptionId = $exception->id;
            }

            $request->update([
                'status' => $outcome,
                'decided_by' => $decider->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'exception_id' => $exceptionId,
            ]);

            return $request->fresh();
        });
    }

    /** Revokes an active exception before its natural expiry — auditable (revoked_by/revoked_at), never a silent delete. */
    public function revokeException(KycWithdrawalException $exception, User $revoker): KycWithdrawalException
    {
        $exception->update(['revoked_at' => now(), 'revoked_by' => $revoker->id]);

        return $exception->fresh();
    }
}
