<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Web (session-guarded) FCM token registration for the provider, customer
 * and admin web apps. The native mobile app has its own Sanctum-guarded
 * equivalent (AuthController::registerDevice, POST /api/auth/device) — this
 * is the browser counterpart the Phase 2 push work needed, since no web
 * user could previously get an fcm_token written at all.
 *
 * A browser's token goes on users.fcm_token. An Android app's token goes in
 * user_push_tokens, one per app (App\Support\PushApp), so one account can
 * use the Partner and Rider apps on one phone and each rings for its own jobs.
 */
class PushTokenController extends Controller
{
    /** Store / refresh the current user's web push token. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        // Inside an Android app: each app keeps its own token, so the Partner app and the Rider app on one phone
        // both ring for their own jobs (owner rule 2026-11-06). Another account signed in before on this phone
        // stops getting its notifications.
        $app = \App\Support\NativeApp::flavor();
        if ($app !== null) {
            \App\Support\PushApp::register($request->user(), $app, $validated['token']);

            return response()->json(['message' => 'Push token registered.']);
        }

        // One browser, one account.
        User::query()
            ->where('fcm_token', $validated['token'])
            ->whereKeyNot($request->user()->getKey())
            ->update(['fcm_token' => null]);

        $request->user()->update(['fcm_token' => $validated['token']]);

        return response()->json(['message' => 'Push token registered.']);
    }

    /** Clear it — called when a user turns notifications off or revokes permission. */
    public function destroy(Request $request): JsonResponse
    {
        $app = \App\Support\NativeApp::flavor();
        if ($app !== null) {
            $request->user()->pushTokens()->where('app', $app)->delete();
        } else {
            $request->user()->update(['fcm_token' => null]);
        }

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
