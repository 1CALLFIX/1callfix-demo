<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Web (session-guarded) FCM token registration for the provider, customer
 * and admin web apps. The native mobile app has its own Sanctum-guarded
 * equivalent (AuthController::registerDevice, POST /api/auth/device) — this
 * is the browser counterpart the Phase 2 push work needed, since no web
 * user could previously get an fcm_token written at all.
 *
 * The token is stored on the single users.fcm_token column (last-writer
 * wins across a user's devices — a documented v1 limitation; a
 * push_subscriptions table is the multi-device upgrade path).
 */
class PushTokenController extends Controller
{
    /** Store / refresh the current user's web push token. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        $request->user()->update(['fcm_token' => $validated['token']]);

        return response()->json(['message' => 'Push token registered.']);
    }

    /** Clear it — called when a user turns notifications off or revokes permission. */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->update(['fcm_token' => null]);

        return response()->json(['message' => 'Push token cleared.']);
    }

    /**
     * Admin-only: flip the "order alerts" opt-in (users.push_ops_alerts)
     * that App\Services\AdminOpsAlertService gates on. Routed under the
     * admin middleware group, so a customer/provider session can't reach it.
     */
    public function opsAlerts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $request->user()->update(['push_ops_alerts' => $validated['enabled']]);

        return response()->json(['message' => 'Order-alert preference saved.', 'enabled' => $validated['enabled']]);
    }
}
