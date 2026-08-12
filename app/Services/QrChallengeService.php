<?php

namespace App\Services;

use App\Models\QrChallenge;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * QR device-pairing challenge lifecycle — see QR_SCAN_ARCHITECTURE.md.
 * Every challenge is short-lived and single-use: create() issues an opaque
 * `token` (goes in the QR, scan/confirm-only) and a SEPARATE `poll_token`
 * (returned only to the initiating desktop, never rendered into the QR —
 * required to poll status or retrieve the resulting session, so a photo of
 * the QR alone can never be used to steal the paired session). confirm()
 * is the ONLY place status ever moves to 'confirmed', guarded by a row
 * lock so two near-simultaneous scans of the same QR can't both win —
 * the same DB::transaction()+lockForUpdate() convention this codebase
 * already uses everywhere else for a one-winner-only decision.
 */
class QrChallengeService
{
    private function expirySeconds(): int
    {
        return (int) Setting::get('auth.qr_challenge_expiry_seconds', 120);
    }

    /** @return array{challenge:QrChallenge, poll_token:string} poll_token is NEVER stored anywhere else — capture it now, it cannot be recovered later. */
    public function create(string $purpose = QrChallenge::PURPOSE_DEVICE_PAIRING, ?array $payload = null, ?string $initiatorIdentifier = null, ?string $initiatorIp = null): array
    {
        $pollToken = QrChallenge::generateToken();

        $challenge = QrChallenge::create([
            'token' => QrChallenge::generateToken(),
            'poll_token' => $pollToken,
            'purpose' => $purpose,
            'status' => QrChallenge::STATUS_PENDING,
            'initiator_identifier' => $initiatorIdentifier,
            'initiator_ip' => $initiatorIp,
            'payload' => $payload,
            'expires_at' => now()->addSeconds($this->expirySeconds()),
        ]);

        return ['challenge' => $challenge, 'poll_token' => $pollToken];
    }

    /**
     * Desktop-side poll, authenticated by poll_token (never the scan
     * token) — confirms status without ever exposing a session. Also
     * lazily flips an expired-but-still-pending row.
     */
    public function statusByPollToken(string $pollToken): ?QrChallenge
    {
        $challenge = QrChallenge::where('poll_token', $pollToken)->first();

        if ($challenge && $challenge->isPending() && $challenge->isExpired()) {
            $challenge->update(['status' => QrChallenge::STATUS_EXPIRED]);
        }

        return $challenge;
    }

    /**
     * @return array{success:bool, reason:?string, challenge:?QrChallenge}
     */
    public function confirm(string $token, string $purpose, User $confirmingUser, ?string $deviceIdentifier = null, ?string $ip = null): array
    {
        return DB::transaction(function () use ($token, $purpose, $confirmingUser, $deviceIdentifier, $ip) {
            $challenge = QrChallenge::where('token', $token)->lockForUpdate()->first();

            if (! $challenge) {
                return ['success' => false, 'reason' => 'not_found', 'challenge' => null];
            }

            if ($challenge->purpose !== $purpose) {
                return ['success' => false, 'reason' => 'wrong_purpose', 'challenge' => $challenge];
            }

            if ($challenge->status === QrChallenge::STATUS_CONFIRMED) {
                // Already used — a screenshot/replay of an old QR must never
                // confirm twice, even against the same real challenge.
                return ['success' => false, 'reason' => 'already_used', 'challenge' => $challenge];
            }

            if ($challenge->status === QrChallenge::STATUS_REVOKED) {
                return ['success' => false, 'reason' => 'revoked', 'challenge' => $challenge];
            }

            if ($challenge->isExpired()) {
                $challenge->update(['status' => QrChallenge::STATUS_EXPIRED]);

                return ['success' => false, 'reason' => 'expired', 'challenge' => $challenge];
            }

            $challenge->update([
                'status' => QrChallenge::STATUS_CONFIRMED,
                'confirmed_by_user_id' => $confirmingUser->id,
                'confirmed_device_identifier' => $deviceIdentifier,
                'confirmed_ip' => $ip,
                'confirmed_at' => now(),
            ]);

            return ['success' => true, 'reason' => null, 'challenge' => $challenge->fresh()];
        });
    }

    /**
     * One-time session issuance for the desktop side, once confirmed —
     * guarded by the same row lock, so a retried/duplicate poll can never
     * issue a second token for the same challenge.
     *
     * @return array{success:bool, reason:?string, token:?string, user:?User}
     */
    public function claimSession(string $pollToken): array
    {
        return DB::transaction(function () use ($pollToken) {
            $challenge = QrChallenge::where('poll_token', $pollToken)->lockForUpdate()->first();

            if (! $challenge) {
                return ['success' => false, 'reason' => 'not_found', 'token' => null, 'user' => null];
            }

            if ($challenge->status !== QrChallenge::STATUS_CONFIRMED) {
                return ['success' => false, 'reason' => 'not_confirmed', 'token' => null, 'user' => null];
            }

            if ($challenge->session_claimed_at) {
                return ['success' => false, 'reason' => 'already_claimed', 'token' => null, 'user' => null];
            }

            $user = User::find($challenge->confirmed_by_user_id);
            if (! $user) {
                return ['success' => false, 'reason' => 'not_found', 'token' => null, 'user' => null];
            }

            $challenge->update(['session_claimed_at' => now()]);
            $plainTextToken = $user->createToken('qr-pairing:'.$challenge->id)->plainTextToken;

            return ['success' => true, 'reason' => null, 'token' => $plainTextToken, 'user' => $user];
        });
    }

    public function revoke(string $pollToken): bool
    {
        $challenge = QrChallenge::where('poll_token', $pollToken)->where('status', QrChallenge::STATUS_PENDING)->first();

        if (! $challenge) {
            return false;
        }

        $challenge->update(['status' => QrChallenge::STATUS_REVOKED]);

        return true;
    }
}
