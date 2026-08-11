<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use App\Models\FieldWorker;
use App\Models\PartnerWorker;
use App\Models\Provider;
use App\Notifications\Support\ChannelResolver;
use App\Notifications\WorkAssignmentNotification;
use Illuminate\Support\Facades\DB;

/**
 * Phase B0.2 — a Partner delegates an already-accepted booking to one of
 * their Workers. Does NOT change booking.provider_id (the Partner stays
 * the accountable party for commission/settlement — CommissionService is
 * untouched) and does NOT change booking.status — assigned_worker_id is a
 * side annotation on top of the existing FSM, not a new state. Uses
 * Phase B0.1's field_workers/partner_workers tables exactly as built,
 * unmodified.
 */
class AssignBookingToWorkerAction
{
    /**
     * Only these WorkerTypes qualify a worker to execute a Service-module
     * job (see App\Support\WorkerTypes for the full registry — the
     * delivery/taxi types don't apply here). Kept local to this action
     * rather than added to WorkerTypes itself, to leave the Phase B0.1
     * file untouched.
     */
    private const SERVICE_CAPABILITY_TYPES = ['service_technician', 'handyman', 'helper'];

    /**
     * Assignment is only safe before work has actually started — the same
     * "accepted, not yet in progress" window AcceptBookingAction's own
     * status transition lands in. Reassigning mid-job isn't handled here
     * (out of B0.2 scope, see the acceptance report's open decisions).
     */
    private const ASSIGNABLE_STATUSES = ['assigned', 'provider_en_route'];

    /** @throws \RuntimeException on any ownership/eligibility failure — every branch below is a real, checked business rule, never a silent guess */
    public function execute(int $bookingId, Provider $partner, int $fieldWorkerId): Booking
    {
        $booking = DB::transaction(function () use ($bookingId, $partner, $fieldWorkerId) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->provider_id !== $partner->id) {
                throw new \RuntimeException('This booking is not accepted by you.');
            }

            if (! in_array($booking->status, self::ASSIGNABLE_STATUSES, true)) {
                throw new \RuntimeException("Booking [{$bookingId}] cannot be assigned to a worker from status '{$booking->status}'.");
            }

            $worker = FieldWorker::find($fieldWorkerId);
            if (! $worker || ! $worker->is_active) {
                throw new \RuntimeException('This worker is not available.');
            }

            $activeLink = PartnerWorker::where('provider_id', $partner->id)
                ->where('field_worker_id', $worker->id)
                ->where('status', 'active')
                ->exists();
            if (! $activeLink) {
                throw new \RuntimeException('This worker is not an active member of your team.');
            }

            $booking->loadMissing('service');
            $categoryId = $booking->service->category_id;
            $hasCapability = $worker->capabilities()
                ->whereIn('capability_type', self::SERVICE_CAPABILITY_TYPES)
                ->where(fn ($q) => $q->whereNull('service_category_id')->orWhere('service_category_id', $categoryId))
                ->exists();
            if (! $hasCapability) {
                throw new \RuntimeException('This worker does not have the required service capability.');
            }

            $booking->assigned_worker_id = $worker->id;
            $booking->save();

            // Same pattern AdminReassignBookingAction already uses: a
            // history row and a BookingStatusUpdated event even though
            // `status` itself didn't change — this is still a real,
            // significant state change worth recording/broadcasting.
            $booking->statusHistory()->create([
                'status' => $booking->status,
                'changed_by' => $partner->user_id,
                'note' => "Assigned to field worker #{$worker->id} by partner",
                'changed_at' => now(),
            ]);

            event(new BookingStatusUpdated($booking));

            return $booking->fresh();
        });

        if ($booking->assignedWorker && $booking->assignedWorker->user) {
            $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);
            $booking->assignedWorker->user->notify(new WorkAssignmentNotification('assigned', $booking, $channels));
        }

        return $booking;
    }
}
