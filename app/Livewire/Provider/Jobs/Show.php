<?php

namespace App\Livewire\Provider\Jobs;

use App\Actions\CompleteBookingAction;
use App\Actions\MarkEnRouteAction;
use App\Actions\StartBookingAction;
use App\Livewire\Provider\Concerns\DetectsStuckJob;
use App\Livewire\Provider\Concerns\InteractsWithProvider;
use App\Models\Booking;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * PHASE PW1 §6 — one job this partner holds: customer contact + address
 * (revealed now it's theirs), the status timeline, and the OTP field for
 * the current step.
 *
 *   - En route       → App\Actions\MarkEnRouteAction (Phase PN1 — the
 *                      transition into `provider_en_route`, which the FSM
 *                      already accepted everywhere but nothing entered).
 *                      Optional: Start still works straight from `assigned`.
 *   - Start-OTP      → App\Actions\StartBookingAction (verbatim the call
 *                      WorkerJobController::start() makes). Accepts
 *                      `assigned` or `provider_en_route`.
 *   - Completion-OTP → App\Actions\CompleteBookingAction (verbatim the call
 *                      API\DispatchController::complete() makes).
 *
 * Nothing here re-implements a transition, an OTP check, a commission or a
 * wallet credit. BookingOtpException extends \RuntimeException, so one catch
 * covers wrong / expired / used / cap-exhausted codes; the Action has
 * already committed the attempt-counter increment separately.
 *
 * IDOR: 404 (never 403 — the codebase convention) on any booking not
 * assigned to this partner; #[Locked] pins the id.
 */
class Show extends Component
{
    use DetectsStuckJob;
    use InteractsWithProvider;

    #[Locked]
    public int $bookingId;

    public string $otp = '';

    public string $error = '';

    public string $notice = '';

    /**
     * Phase PN1 — last status this component has already alerted the
     * provider about. Seeded on mount so the first render never fires a
     * spurious alert; updated by render() and by the action methods so a
     * self-initiated transition (the provider's own tap) doesn't chime at
     * them — only an externally-driven change (an admin cancel, a hold)
     * does.
     */
    #[Locked]
    public string $lastSeenStatus = '';

    public function mount(Booking $booking): void
    {
        abort_unless($booking->provider_id === $this->provider()->id, 404);
        $this->bookingId = $booking->id;
        $this->lastSeenStatus = $booking->status;
    }

    private function job(): Booking
    {
        $booking = Booking::with([
            'service', 'address', 'customer:id,name,phone',
            // Supplies TimezoneResolver's franchise->country->default_timezone
            // for the timeline's timestamps (see the Blade view).
            'franchise.country',
            'statusHistory' => fn ($q) => $q->orderBy('changed_at')->orderBy('id'),
        ])->findOrFail($this->bookingId);

        abort_unless($booking->provider_id === $this->provider()->id, 404);

        return $booking;
    }

    public function enRoute(MarkEnRouteAction $action): void
    {
        $this->reset('error', 'notice');

        try {
            $action->execute($this->bookingId, $this->provider(), auth()->id());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        // The provider initiated this — don't let render() chime at them for
        // their own tap.
        $this->lastSeenStatus = 'provider_en_route';
        $this->notice = "Marked on the way.";
    }

    public function start(StartBookingAction $action): void
    {
        $this->reset('error', 'notice');
        $this->validate(['otp' => ['required', 'string', 'max:12']], ['otp.required' => 'Enter the start OTP.']);

        try {
            $action->execute($this->bookingId, trim($this->otp), auth()->id());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->reset('otp');
        $this->lastSeenStatus = 'in_progress';
        $this->notice = 'Job started.';
    }

    public function complete(CompleteBookingAction $action): void
    {
        $this->reset('error', 'notice');
        $this->validate(['otp' => ['required', 'string', 'max:12']], ['otp.required' => 'Enter the completion OTP.']);

        try {
            $action->execute($this->bookingId, $this->provider(), trim($this->otp));
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->reset('otp');
        $this->lastSeenStatus = 'completed';
        $this->notice = 'Job completed.';
    }

    public function render()
    {
        $booking = $this->job();

        // Phase PN1 — an externally-driven status change on the job the
        // provider is looking at (an admin cancel, a dispatcher hold, a
        // resume): chime + tab-hidden OS notification via provider-alerts.js.
        // Self-initiated transitions already updated $lastSeenStatus in their
        // action method, so this only fires for changes the provider didn't
        // make here.
        if ($this->lastSeenStatus !== '' && $booking->status !== $this->lastSeenStatus) {
            $this->dispatch('provider-alert-status', ...$this->statusAlertCopy($booking->status));
            $this->lastSeenStatus = $booking->status;
        }

        return view('livewire.provider.jobs.show', [
            'booking' => $booking,
            'stuckMinutes' => $this->stuckMinutes($booking),
            'isLive' => in_array($booking->status, ['assigned', 'provider_en_route', 'in_progress'], true),
            'commission' => $booking->status === 'completed' ? $booking->commission()->first() : null,
        ])->layout('components.layouts.provider', ['title' => 'Job '.$booking->code]);
    }

    /** @return array{title:string,body:string} */
    private function statusAlertCopy(string $status): array
    {
        return match ($status) {
            'provider_en_route' => ['title' => 'On the way', 'body' => 'This job is marked on the way.'],
            'in_progress' => ['title' => 'Job started', 'body' => 'This job is now in progress.'],
            'on_hold' => ['title' => 'Job on hold', 'body' => 'This job has been put on hold.'],
            'completed' => ['title' => 'Job completed', 'body' => 'This job has been completed.'],
            'cancelled' => ['title' => 'Job cancelled', 'body' => 'This job has been cancelled.'],
            default => ['title' => 'Job updated', 'body' => 'This job\'s status changed to '.str_replace('_', ' ', $status).'.'],
        };
    }
}
