<?php

namespace App\Http\Controllers\API;

use App\Actions\MarkEnRouteAction;
use App\Actions\MarkSparesAvailableAction;
use App\Actions\PlaceBookingOnHoldAction;
use App\Actions\ResumeBookingAction;
use App\Actions\StartBookingAction;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Support\Journey\JourneyBuilder;
use App\Support\Journey\JourneyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REF 1CF-JOURNEY-001 — the job journey for the native apps.
 *
 *   GET  /api/bookings/{id}/journey              customer / provider / assigned worker
 *   POST /api/bookings/{id}/en-route             provider or assigned worker   (assigned -> provider_en_route)
 *   POST /api/bookings/{id}/start                provider or assigned worker   (start OTP)
 *   POST /api/bookings/{id}/hold-for-spares      provider or assigned worker   (in_progress -> on_hold, awaiting_spares)
 *   POST /api/bookings/{id}/spares-available     provider or assigned worker   (marks the spares step)
 *   POST /api/bookings/{id}/resume               provider or assigned worker   (spares hold -> in_progress)
 *
 * Accept and complete already exist (DispatchController). Every transition here is the SAME Action the
 * provider web screen and the admin panel call — this controller only authenticates, authorises
 * ownership and shapes the response. Ownership: the booking's accountable provider, or the field
 * worker it is delegated to; anyone else gets 403 (404 for the read-only journey).
 */
class JobJourneyController extends Controller
{
    public function show(Request $request, int $bookingId): JsonResponse
    {
        $booking = Booking::with(['provider.user', 'franchise.country', 'payment', 'statusHistory' => fn ($q) => $q->orderBy('changed_at')->orderBy('id')])->find($bookingId);
        $user = $request->user();

        $allowed = $booking && (
            $booking->customer_id === $user->id
            || ($user->providerProfile && $booking->provider_id === $user->providerProfile->id)
            || ($user->fieldWorkerProfile && $booking->assigned_worker_id === $user->fieldWorkerProfile->id)
        );

        if (! $allowed) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        return response()->json($this->payload($booking));
    }

    public function enRoute(Request $request, int $bookingId, MarkEnRouteAction $action): JsonResponse
    {
        return $this->act($request, $bookingId, 'On the way.', function (Booking $booking) use ($action, $request) {
            $action->execute($booking->id, $booking->provider, $request->user()->id);
        });
    }

    public function start(Request $request, int $bookingId, StartBookingAction $action): JsonResponse
    {
        $validated = $request->validate(['otp' => 'required|string|size:4']);

        return $this->act($request, $bookingId, 'Job started.', function (Booking $booking) use ($action, $validated, $request) {
            $action->execute($booking->id, $validated['otp'], $request->user()->id);
        });
    }

    public function holdForSpares(Request $request, int $bookingId, PlaceBookingOnHoldAction $action): JsonResponse
    {
        // REF 1CF-CANCEL-POLICY-001 — the interim-work declaration is mandatory (it is what the customer is charged on).
        $validated = $request->validate([
            'note' => 'nullable|string|max:500',
            'work_amount' => 'required|numeric|min:0',
            'sourced_by' => 'required|in:provider,platform,customer',
            'expected_at' => 'required|date|after_or_equal:today',
            'evidence' => 'nullable|array|max:5',
            'evidence.*' => 'file|mimes:jpg,jpeg,png,webp,pdf|max:4096',
        ]);

        return $this->act($request, $bookingId, 'Job on hold while you get the spare parts.', function (Booking $booking) use ($action, $validated, $request) {
            if ($booking->status !== 'in_progress') {
                throw new \RuntimeException('You can wait for spare parts once the job is in progress.');
            }

            $paths = array_map(fn ($file) => $file->store("booking-evidence/{$booking->id}", 'public'), $request->file('evidence', []));

            try {
                $action->execute($booking->id, 'awaiting_spares', $validated['note'] ?? 'Provider is waiting for spare parts', [
                    'work_amount' => $validated['work_amount'],
                    'sourced_by' => $validated['sourced_by'],
                    'expected_at' => $validated['expected_at'],
                    'evidence' => $paths,
                ]);
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException($e->getMessage());
            }
        });
    }

    public function sparesAvailable(Request $request, int $bookingId, MarkSparesAvailableAction $action): JsonResponse
    {
        return $this->act($request, $bookingId, 'Marked: spare parts are available.', function (Booking $booking) use ($action, $request) {
            $action->execute($booking->id, $request->user()->id);
        });
    }

    public function resume(Request $request, int $bookingId, ResumeBookingAction $action): JsonResponse
    {
        return $this->act($request, $bookingId, 'Work resumed.', function (Booking $booking) use ($action) {
            if ($booking->status !== 'on_hold' || $booking->hold_reason !== 'awaiting_spares') {
                throw new \RuntimeException('Only a job held for spare parts can be resumed here — your dispatcher handles other holds.');
            }
            $action->execute($booking->id, 'Spares in hand');
        });
    }

    /** Ownership check + run + uniform response. 403 not-yours, 409 for a refused transition. */
    private function act(Request $request, int $bookingId, string $message, callable $run): JsonResponse
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
            $run($booking);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => $message] + $this->payload($booking->fresh(['provider.user', 'franchise.country', 'payment', 'statusHistory'])));
    }

    /** @return array{booking: array, journey: array} */
    private function payload(Booking $booking): array
    {
        $booking->loadMissing('statusHistory');
        $journey = JourneyBuilder::build('service', $booking->status, $booking->statusHistory, JourneyContext::forBooking($booking));

        return [
            'booking' => ['id' => $booking->id, 'code' => $booking->code, 'status' => $booking->status],
            'journey' => $journey ? JourneyBuilder::toApi($journey) : null,
        ];
    }
}
