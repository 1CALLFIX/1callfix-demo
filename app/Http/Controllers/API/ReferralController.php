<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Referrals\ReferralPresenter;
use Illuminate\Http\Request;

/**
 * REF 1CF-PROMPT-20260925-EARN4 — Stage 4. GET /api/referrals/mine for the
 * app: the caller's code, share link, rules and their own referrals
 * (paginated). Takes no ids — the caller is always $request->user(), so
 * there is no foreign-id surface. 404 while earnings.referral_tab or
 * referral.enabled is off for the caller, exactly like the web tab.
 */
class ReferralController extends Controller
{
    public function mine(Request $request, ReferralPresenter $presenter)
    {
        $user = $request->user();
        abort_unless($presenter->visibleFor($user), 404);

        $page = $presenter->paginate($user, min(50, max(1, (int) $request->query('per_page', 20))));

        return response()->json([
            'code' => $user->referral_code,
            'share_link' => $presenter->shareLink($user),
            'rules' => $presenter->rules($user),
            'referral_balance' => app(\App\Services\ReferralBalanceService::class)->available($user),
            'referrals' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
