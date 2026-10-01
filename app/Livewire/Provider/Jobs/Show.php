<?php

namespace App\Livewire\Provider\Jobs;

use App\Actions\CompleteBookingAction;
use App\Actions\FlagProviderLeftAction;
use App\Actions\MarkEnRouteAction;
use App\Actions\MarkSparesAvailableAction;
use App\Actions\PlaceBookingOnHoldAction;
use App\Actions\ProposeExtraWorkAction;
use App\Actions\ResumeBookingAction;
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

    public string $extraDescription = '';

    public string $extraAmount = '';

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

    /**
     * REF 1CF-JOURNEY-001 — "waiting for spares": the in-progress -> on hold lane of the job journey.
     * Providers may only hold for spare parts (every other hold reason stays a dispatcher decision).
     */
    public function holdForSpares(PlaceBookingOnHoldAction $action): void
    {
        $this->reset('error', 'notice');

        $booking = $this->job();
        if ($booking->status !== 'in_progress') {
            $this->error = 'You can wait for spare parts once the job is in progress.';

            return;
        }

        try {
            $action->execute($this->bookingId, 'awaiting_spares', 'Provider is waiting for spare parts');
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->lastSeenStatus = 'on_hold';
        $this->notice = 'Job is on hold while you get the spare parts. The customer can see this.';
    }

    public function sparesAvailable(MarkSparesAvailableAction $action): void
    {
        $this->reset('error', 'notice');
        $this->job(); // ownership check (404 for anyone else's job)

        try {
            $action->execute($this->bookingId, auth()->id());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->notice = 'Marked: spare parts are available. Resume the work when you are ready.';
    }

    public function resumeJob(ResumeBookingAction $action): void
    {
        $this->reset('error', 'notice');

        $booking = $this->job();
        if ($booking->status !== 'on_hold' || $booking->hold_reason !== 'awaiting_spares') {
            $this->error = 'Only a job held for spare parts can be resumed from here — your dispatcher handles other holds.';

            return;
        }

        try {
            $action->execute($this->bookingId, 'Spares in hand');
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->lastSeenStatus = 'in_progress';
        $this->notice = 'Work resumed.';
    }

    /** REF 1CF-EXTRAWORK-001 — ask the customer to approve extra work; the job pauses until they answer. */
    public function proposeExtraWork(ProposeExtraWorkAction $action): void
    {
        $this->reset('error', 'notice');
        $this->validate([
            'extraDescription' => ['required', 'string', 'min:3', 'max:200'],
            'extraAmount' => ['required', 'numeric', 'gt:0'],
        ], [
            'extraDescription.required' => 'Describe the extra work.',
            'extraAmount.required' => 'Enter the extra amount.',
        ]);

        $this->job(); // ownership check

        try {
            $action->execute($this->bookingId, $this->provider(), trim($this->extraDescription), (float) $this->extraAmount);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->reset('extraDescription', 'extraAmount');
        $this->lastSeenStatus = 'on_hold';
        $this->notice = 'Sent to the customer. The job is paused until they approve or decline.';
    }

    /** REF 1CF-JOURNEY-001 — the professional cannot continue; the dispatcher is alerted and hands the job on. */
    public function cannotContinue(FlagProviderLeftAction $action): void
    {
        $this->reset('error', 'notice');
        $this->job(); // ownership check

        try {
            $action->execute($this->bookingId, 'provider', auth()->id());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->lastSeenStatus = 'on_hold';
        $this->notice = 'Reported. Your dispatcher will arrange for another professional to finish the job.';
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
            'isLive' => in_array($booking->status, ['assigned', 'provider_en_route', 'in_progress', 'on_hold'], true),
            'journey' => \App\Support\Journey\JourneyBuilder::build('service', $booking->status, $booking->statusHistory, \App\Support\Journey\JourneyContext::forBooking($booking)),
            'sparesMarked' => $booking->status === 'on_hold' && $booking->statusHistory->contains(fn ($h) => $h->status === 'on_hold' && str_starts_with((string) $h->note, \App\Support\Journey\JourneyBuilder::SPARES_NOTE) && (! $booking->on_hold_since || $h->changed_at >= $booking->on_hold_since)),
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
