<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\BusinessAccount;
use App\Models\Plan;
use App\Services\Plans\MembershipPresenter;
use App\Services\Plans\SubscriptionService;
use Illuminate\Http\Request;

/**
 * Browse + purchase. All limits (eligibility, active-plan check) are
 * enforced inside SubscriptionService/EligibilityService — never trusted to
 * the client, per the approved plan §18.
 */
class PlanController extends Controller
{
    /** GET /api/plans?acting_as=customer|provider|business_account */
    public function index(Request $request, MembershipPresenter $presenter)
    {
        $validated = $request->validate(['acting_as' => ['required', 'in:customer,provider,business_account']]);
        $user = $request->user();

        $plans = Plan::where('is_active', true)
            ->where('eligible_actor_type', $validated['acting_as'])
            ->where(function ($q) use ($user) {
                $q->where('scope_type', 'global')
                    ->orWhere(fn ($qq) => $qq->where('scope_type', 'franchise')->where('scope_id', $user->franchise_id))
                    ->orWhere(fn ($qq) => $qq->where('scope_type', 'zone')->where('scope_id', $user->zone_id));
            })
            ->with('entitlements.targets')
            ->get();

        // Superset of the raw plan row: every existing field is still there, plus
        // the customer-facing shape (validity, terms, address lock, and each
        // benefit's name / total / choices / scope) so a client needs no second call.
        return response()->json([
            'plans' => $plans->map(function ($plan) use ($presenter) {
                $card = $presenter->plan($plan);
                $raw = $plan->toArray();
                $raw['entitlements'] = collect($raw['entitlements'] ?? [])
                    ->map(fn ($e) => $e + (collect($card['entitlements'])->firstWhere('entitlement_id', $e['id']) ?? []))
                    ->all();

                return $raw + [
                    'validity_label' => $card['validity_label'],
                    'address_locked' => $card['address_locked'],
                    'terms' => $card['terms'],
                ];
            })->values(),
        ]);
    }

    /** POST /api/plans/{plan}/subscribe */
    public function subscribe(Request $request, int $planId, SubscriptionService $service)
    {
        $plan = Plan::findOrFail($planId);
        $validated = $request->validate([
            'acting_as' => ['required', 'in:customer,provider,business_account'],
            'business_account_id' => ['required_if:acting_as,business_account', 'integer'],
            // The saved address an address-locked plan (e.g. Prime Silver) is registered to.
            'address_id' => ['nullable', 'integer'],
        ]);

        $actor = $request->user();
        if ($validated['acting_as'] === 'business_account') {
            $actor = BusinessAccount::where('id', $validated['business_account_id'])
                ->where('owner_user_id', $request->user()->id)
                ->firstOrFail();
        }

        try {
            $result = $service->initiateSubscribe($actor, $validated['acting_as'], $plan, $validated['address_id'] ?? null);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
