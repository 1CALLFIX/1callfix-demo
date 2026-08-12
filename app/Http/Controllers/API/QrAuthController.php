<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\QrChallenge;
use App\Services\QrChallengeService;
use Illuminate\Http\Request;

/**
 * QR device pairing — "Login with the 1CallFix App" (QR_SCAN_ARCHITECTURE.md).
 * Deliberately separate from AuthController's OTP login: this is a SECOND
 * WAY to reach the same Sanctum session, not a second authentication
 * system — a QR challenge only ever resolves through an already-OTP-
 * authenticated mobile session confirming it (confirm() requires
 * auth:sanctum). Every challenge is short-lived, single-use, and never a
 * credential by itself — see the security notes throughout.
 */
class QrAuthController extends Controller
{
    /**
     * POST /api/auth/qr/create — unauthenticated (this is how the flow
     * BEGINS, before anyone is logged in on the initiating side). Returns
     * TWO distinct secrets: `qr_token` (goes into the rendered QR image —
     * scan/confirm-only) and `poll_token` (kept ONLY by the caller,
     * required for every subsequent status/claim call). A photo of the
     * displayed QR reveals only qr_token, never enough to poll for or
     * steal the resulting session.
     */
    public function create(Request $request, QrChallengeService $service)
    {
        $validated = $request->validate(['initiator_identifier' => ['nullable', 'string', 'max:255']]);

        ['challenge' => $challenge, 'poll_token' => $pollToken] = $service->create(
            QrChallenge::PURPOSE_DEVICE_PAIRING,
            null,
            $validated['initiator_identifier'] ?? null,
            $request->ip(),
        );

        return response()->json([
            'qr_token' => $challenge->token,
            'poll_token' => $pollToken,
            'expires_at' => $challenge->expires_at,
        ]);
    }

    /**
     * GET /api/auth/qr/status — unauthenticated, authenticated instead by
     * possession of poll_token (never the scan token) — never reveals a
     * session, just lifecycle state.
     */
    public function status(Request $request, QrChallengeService $service)
    {
        $validated = $request->validate(['poll_token' => ['required', 'string']]);

        $challenge = $service->statusByPollToken($validated['poll_token']);

        if (! $challenge) {
            return response()->json(['message' => 'Challenge not found.'], 404);
        }

        return response()->json([
            'status' => $challenge->status,
            'expires_at' => $challenge->expires_at,
            'ready_to_claim' => $challenge->status === QrChallenge::STATUS_CONFIRMED && ! $challenge->session_claimed_at,
        ]);
    }

    /**
     * POST /api/auth/qr/claim — unauthenticated, authenticated by
     * poll_token. One-time only: the first successful claim issues a real
     * Sanctum token and marks the challenge claimed; every call after
     * that (retry, replay) gets 'already_claimed', never a second token.
     */
    public function claim(Request $request, QrChallengeService $service)
    {
        $validated = $request->validate(['poll_token' => ['required', 'string']]);

        $result = $service->claimSession($validated['poll_token']);

        if (! $result['success']) {
            $status = match ($result['reason']) {
                'not_confirmed' => 409,
                'already_claimed' => 410,
                default => 404,
            };

            return response()->json(['message' => match ($result['reason']) {
                'not_confirmed' => 'This challenge has not been confirmed yet.',
                'already_claimed' => 'This challenge has already been claimed.',
                default => 'Challenge not found.',
            }], $status);
        }

        $user = $result['user'];

        return response()->json([
            'token' => $result['token'],
            'user' => ['id' => $user->id, 'name' => $user->name, 'phone' => $user->phone, 'role' => $user->role],
        ]);
    }

    /**
     * POST /api/auth/qr/confirm — requires auth:sanctum: only an
     * ALREADY-authenticated mobile session (logged in via its own OTP
     * flow) can confirm a pairing challenge for someone else's desktop.
     * Scanning a QR is never itself a form of authentication — it's an
     * authenticated user vouching for a specific challenge.
     */
    public function confirm(Request $request, QrChallengeService $service)
    {
        $validated = $request->validate(['qr_token' => ['required', 'string']]);

        $result = $service->confirm(
            $validated['qr_token'],
            QrChallenge::PURPOSE_DEVICE_PAIRING,
            $request->user(),
            $request->header('X-Device-Id'),
            $request->ip(),
        );

        if (! $result['success']) {
            $status = match ($result['reason']) {
                'already_used' => 409,
                'expired' => 410,
                'revoked' => 410,
                'wrong_purpose' => 422,
                default => 404,
            };

            return response()->json(['message' => match ($result['reason']) {
                'already_used' => 'This QR code has already been used.',
                'revoked' => 'This QR code was cancelled.',
                'expired' => 'This QR code has expired. Ask for a new one.',
                'wrong_purpose' => 'This QR code is not valid for login pairing.',
                default => 'QR code not found or invalid.',
            }], $status);
        }

        return response()->json(['message' => 'Login confirmed. The other device will now be signed in.']);
    }

    /** POST /api/auth/qr/revoke — the initiating side cancelling its own still-pending challenge. */
    public function revoke(Request $request, QrChallengeService $service)
    {
        $validated = $request->validate(['poll_token' => ['required', 'string']]);

        $revoked = $service->revoke($validated['poll_token']);

        return response()->json(['message' => $revoked ? 'Challenge revoked.' : 'Nothing to revoke.']);
    }
}
