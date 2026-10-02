<?php

namespace App\Http\Controllers\API;

use App\Actions\CheckInArrivalAction;
use App\Actions\CustomerCancelBookingAction;
use App\Actions\LogCallAttemptAction;
use App\Actions\ProviderCancelBookingAction;
use App\Actions\RespondToBookingQuoteAction;
use App\Actions\SendBookingQuoteAction;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Cancellation\CancellationBlockedException;
use App\Services\Cancellation\CancellationPolicy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REF 1CF-CANCEL-POLICY-001 — the professional-side and quote/charge endpoints of the cancellation policy.
 * Every endpoint authenticates, checks ownership and calls the SAME Action the web screens and admin panel call.
 *
 *   POST /api/bookings/{id}/arrive               provider  {lat, lng}      GPS-verified arrival check-in
 *   POST /api/bookings/{id}/quote                provider  {amount}        in-app price quote
 *   POST /api/bookings/{id}/call-attempt         provider                  log one in-app call to the customer
 *   POST /api/bookings/{id}/provider-cancel      provider  {reason, note?} own_reason | customer_unreachable | quote_rejected
 *   POST /api/booking-quotes/{id}/respond        customer  {accept}        accept / reject a quote
 *   POST /api/cancellation-requests/{id}/pay     customer                  settle a charge raised by the professional's cancel
 *   GET  /api/cancellation/policy                any user                  the customer-facing policy text
 */
class CancellationFlowController extends Controller
{
    public function arrive(Request $request, int $bookingId, CheckInArrivalAction $action): JsonResponse
    {
        $data = $request->validate(['lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180']);

        return $this->asProvider($request, $bookingId, function (Booking $b) use ($action, $data, $request) {
            $booking = $action->execute($b->id, $b->provider, (float) $data['lat'], (float) $data['lng'], $request->user()->id);

            return ['message' => 'Arrival verified.', 'arrival_verified_at' => $booking->arrival_verified_at, 'distance_m' => $booking->arrival_distance_m];
        });
    }

    public function quote(Request $request, int $bookingId, SendBookingQuoteAction $action): JsonResponse
    {
        $data = $request->validate(['amount' => 'required|numeric|min:1']);

        return $this->asProvider($request, $bookingId, function (Booking $b) use ($action, $data) {
            $quote = $action->execute($b->id, $b->provider, (float) $data['amount']);

            return ['message' => 'Quote sent to the customer.', 'quote' => ['id' => $quote->id, 'amount' => $quote->amount, 'status' => $quote->status]];
        });
    }

    public function callAttempt(Request $request, int $bookingId, LogCallAttemptAction $action): JsonResponse
    {
        return $this->asProvider($request, $bookingId, function (Booking $b) use ($action) {
            return ['message' => 'Call attempt logged.', 'attempts' => $action->execute($b->id, $b->provider)];
        });
    }

    public function providerCancel(Request $request, int $bookingId, ProviderCancelBookingAction $action): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|in:'.implode(',', ProviderCancelBookingAction::REASONS), 'note' => 'nullable|string|max:500']);

        return $this->asProvider($request, $bookingId, function (Booking $b) use ($action, $data) {
            $result = $action->execute($b->id, $b->provider, $data['reason'], $data['note'] ?? null);

            return [
                'message' => 'Booking cancelled.',
                'charge' => $result['charge'],
                'booking' => ['id' => $result['booking']->id, 'status' => $result['booking']->status],
                'payment_request_id' => $result['request']?->id,
            ];
        });
    }

    public function respondToQuote(Request $request, int $quoteId, RespondToBookingQuoteAction $action): JsonResponse
    {
        $data = $request->validate(['accept' => 'required|boolean']);

        try {
            $quote = $action->execute($quoteId, $request->user()->id, (bool) $data['accept']);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Quote not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Thanks — your answer was sent.', 'quote' => ['id' => $quote->id, 'status' => $quote->status]]);
    }

    public function payCharge(Request $request, int $requestId, CustomerCancelBookingAction $action): JsonResponse
    {
        try {
            $r = $action->payOutstandingCharge($requestId, $request->user()->id);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['outcome' => $r['outcome'], 'charge' => $r['charge'], 'order' => $r['order']]);
    }

    public function policy(CancellationPolicy $policy): JsonResponse
    {
        return response()->json(['lines' => $policy->policyLines(), 'visit_charge_text' => $policy->visitChargeText()]);
    }

    /** Ownership (the accountable professional or the field worker the job is delegated to) + uniform error shape. */
    private function asProvider(Request $request, int $bookingId, callable $run): JsonResponse
    {
        $booking = Booking::find($bookingId);
        $user = $request->user();

        $owns = $booking && (
            ($user->providerProfile && $booking->provider_id === $user->providerProfile->id)
            || ($user->fieldWorkerProfile && $booking->assigned_worker_id === $user->fieldWorkerProfile->id)
        );
        if (! $owns) {
            return response()->json(['message' => 'This job is not assigned to you.'], 403);
        }

        try {
            return response()->json($run($booking));
        } catch (CancellationBlockedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->decision['code'] ?? null], 409);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
