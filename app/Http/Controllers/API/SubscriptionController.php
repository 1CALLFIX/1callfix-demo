<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\BusinessAccount;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Plans\MembershipPresenter;
use App\Services\Plans\SubscriptionService;
use Illuminate\Http\Request;

/**
 * Every action here re-checks ownership server-side (owns() below) — never
 * trusts that a subscription id in the URL belongs to the caller, per the
 * approved plan §18 ("never trust mobile/web UI for entitlement
 * enforcement").
 */
class SubscriptionController extends Controller
{
    /** GET /api/subscriptions/mine */
    public function mine(Request $request)
    {
        $user = $request->user();

        $own = Subscription::where('subscribable_type', User::class)->where('subscribable_id', $user->id);

        $businessAccountIds = BusinessAccount::where('owner_user_id', $user->id)->pluck('id');
        $viaBusinessAccounts = Subscription::where('subscribable_type', BusinessAccount::class)
            ->whereIn('subscribable_id', $businessAccountIds);

        $subscriptions = $own->union($viaBusinessAccounts)->with(['plan', 'registeredAddress:id,label,address_line,pincode'])->latest()->get();

        return response()->json(['subscriptions' => $subscriptions]);
    }

    /**
     * GET /api/subscriptions/{id}/entitlements
     *
     * Superset of the original four keys — entitlement_type, remaining_quantity,
     * remaining_monetary_value and period_end are unchanged — plus what a client
     * needs to tell three `quantity` benefits apart: name, total, remaining,
     * choices, included/excluded scope and the catalog services each covers.
     */
    public function entitlements(Request $request, int $id, MembershipPresenter $presenter)
    {
        $subscription = $this->ownedOrFail($request, $id);

        return response()->json(['entitlements' => $presenter->currentBalances($subscription)->values()]);
    }

    /** GET /api/subscriptions/{id}/usage */
    public function usage(Request $request, int $id, MembershipPresenter $presenter)
    {
        $subscription = $this->ownedOrFail($request, $id);
        $ledger = $subscription->usageLedger()->with(['planEntitlement', 'booking:id,code'])->latest('id')->limit(100)->get();

        return response()->json(['usage' => $ledger->map(fn ($row) => $presenter->usage($row))->values()]);
    }

    /** POST /api/subscriptions/{id}/cancel */
    public function cancel(Request $request, int $id, SubscriptionService $service)
    {
        $subscription = $this->ownedOrFail($request, $id);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $subscription = $service->cancel($subscription, $validated['reason'] ?? 'Cancelled by subscriber');
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['subscription' => $subscription]);
    }

    /** POST /api/subscriptions/{id}/upgrade */
    public function upgrade(Request $request, int $id, SubscriptionService $service)
    {
        return $this->scheduleChange($request, $id, $service, 'upgrade');
    }

    /** POST /api/subscriptions/{id}/downgrade */
    public function downgrade(Request $request, int $id, SubscriptionService $service)
    {
        return $this->scheduleChange($request, $id, $service, 'downgrade');
    }

    /** POST /api/subscriptions/{id}/renew-now */
    public function renewNow(Request $request, int $id, SubscriptionService $service)
    {
        $subscription = $this->ownedOrFail($request, $id);

        try {
            $result = $service->renewNow($subscription);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    private function scheduleChange(Request $request, int $id, SubscriptionService $service, string $type)
    {
        $subscription = $this->ownedOrFail($request, $id);
        $validated = $request->validate(['plan_id' => ['required', 'integer']]);
        $newPlan = Plan::findOrFail($validated['plan_id']);

        try {
            $subscription = $type === 'upgrade'
                ? $service->scheduleUpgrade($subscription, $newPlan)
                : $service->scheduleDowngrade($subscription, $newPlan);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['subscription' => $subscription]);
    }

    private function ownedOrFail(Request $request, int $id): Subscription
    {
        $subscription = Subscription::findOrFail($id);
        $user = $request->user();

        $owns = ($subscription->subscribable_type === User::class && $subscription->subscribable_id === $user->id)
            || ($subscription->subscribable_type === BusinessAccount::class
                && BusinessAccount::where('id', $subscription->subscribable_id)->where('owner_user_id', $user->id)->exists());

        abort_unless($owns, 403, 'Not your subscription.');

        return $subscription;
    }
}
