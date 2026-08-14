<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PaymentAccount;
use App\Services\PaymentAccountService;
use Illuminate\Http\Request;

/**
 * Self-service settlement-account management for Partners/Franchise
 * owners — the real write path payment_accounts never had (mission Phase
 * 9). A user can only ever see/mutate their OWN accounts (user_id
 * checked on every lookup, 404 on mismatch — never a 403 that would
 * confirm another account's existence).
 */
class PaymentAccountController extends Controller
{
    /** GET /api/payment-accounts */
    public function index(Request $request)
    {
        return response()->json(PaymentAccount::where('user_id', $request->user()->id)->get());
    }

    /** POST /api/payment-accounts */
    public function store(Request $request, PaymentAccountService $service)
    {
        $validated = $request->validate([
            'account_type' => ['required', 'in:bank,upi'],
            'account_holder_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'ifsc' => ['nullable', 'string', 'max:20'],
            'upi_id' => ['nullable', 'string', 'max:100'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        try {
            $account = $service->create($request->user(), $validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($account, 201);
    }

    /** PUT /api/payment-accounts/{id} */
    public function update(Request $request, int $id, PaymentAccountService $service)
    {
        $account = PaymentAccount::where('user_id', $request->user()->id)->find($id);
        if (! $account) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $validated = $request->validate([
            'account_type' => ['sometimes', 'in:bank,upi'],
            'account_holder_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'ifsc' => ['nullable', 'string', 'max:20'],
            'upi_id' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $account = $service->update($account, $validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($account);
    }

    /** POST /api/payment-accounts/{id}/set-default */
    public function setDefault(Request $request, int $id, PaymentAccountService $service)
    {
        $account = PaymentAccount::where('user_id', $request->user()->id)->find($id);
        if (! $account) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json($service->setDefault($account));
    }

    /** DELETE /api/payment-accounts/{id} */
    public function destroy(Request $request, int $id, PaymentAccountService $service)
    {
        $account = PaymentAccount::where('user_id', $request->user()->id)->find($id);
        if (! $account) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        try {
            $service->delete($account);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Deleted.']);
    }
}
