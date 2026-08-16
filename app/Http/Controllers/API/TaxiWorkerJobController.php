<?php

namespace App\Http\Controllers\API;

use App\Actions\AcceptTaxiOfferAction;
use App\Actions\CompleteTaxiTripAction;
use App\Actions\StartTaxiTripAction;
use App\Http\Controllers\Controller;
use App\Models\TaxiRide;
use Illuminate\Http\Request;

/**
 * Phase 22.6 (Taxi) — the TaxiRide counterpart to ParcelWorkerJobController/
 * WorkerJobController. Same shape exactly.
 */
class TaxiWorkerJobController extends Controller
{
    /** GET /api/worker/taxi-rides — only rides actually assigned to this driver. */
    public function index(Request $request)
    {
        $worker = $request->user()->fieldWorkerProfile;
        if (! $worker) {
            return response()->json(['message' => 'Only field worker accounts can view assigned taxi rides.'], 403);
        }

        $rides = TaxiRide::where('assigned_worker_id', $worker->id)
            ->with(['pickupAddress', 'dropoffAddress'])
            ->latest()
            ->get();

        return response()->json(['taxi_rides' => $rides]);
    }

    /** POST /api/worker/taxi-rides/{taxiRide}/accept */
    public function accept(Request $request, int $taxiRideId, AcceptTaxiOfferAction $action)
    {
        $worker = $request->user()->fieldWorkerProfile;
        if (! $worker) {
            return response()->json(['message' => 'Only field worker accounts can accept taxi rides.'], 403);
        }

        try {
            $ride = $action->execute($taxiRideId, $worker);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Ride accepted.', 'taxi_ride' => ['id' => $ride->id, 'status' => $ride->status]]);
    }

    /** POST /api/worker/taxi-rides/{taxiRide}/start */
    public function start(Request $request, int $taxiRideId, StartTaxiTripAction $action)
    {
        $worker = $request->user()->fieldWorkerProfile;
        if (! $worker) {
            return response()->json(['message' => 'Only field worker accounts can start taxi rides.'], 403);
        }

        $ride = TaxiRide::find($taxiRideId);
        if (! $ride || $ride->assigned_worker_id !== $worker->id) {
            return response()->json(['message' => 'This ride is not assigned to you.'], 403);
        }

        $validated = $request->validate(['otp' => 'required|string|size:4']);

        try {
            $ride = $action->execute($taxiRideId, $worker, $validated['otp']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Trip started.', 'taxi_ride' => ['id' => $ride->id, 'status' => $ride->status]]);
    }

    /** POST /api/worker/taxi-rides/{taxiRide}/complete */
    public function complete(Request $request, int $taxiRideId, CompleteTaxiTripAction $action)
    {
        $worker = $request->user()->fieldWorkerProfile;
        if (! $worker) {
            return response()->json(['message' => 'Only field worker accounts can complete taxi rides.'], 403);
        }

        $ride = TaxiRide::find($taxiRideId);
        if (! $ride || $ride->assigned_worker_id !== $worker->id) {
            return response()->json(['message' => 'This ride is not assigned to you.'], 403);
        }

        try {
            $ride = $action->execute($taxiRideId, $worker);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Trip completed.',
            'taxi_ride' => ['id' => $ride->id, 'status' => $ride->status, 'price_final' => $ride->price_final],
        ]);
    }
}
