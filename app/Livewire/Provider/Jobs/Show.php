<?php

namespace App\Livewire\Provider\Jobs;

use App\Actions\CompleteBookingAction;
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
 *   - Start-OTP      → App\Actions\StartBookingAction (verbatim the call
 *                      WorkerJobController::start() makes). `assigned` only —
 *                      no provider_en_route step in P1.
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

    public function mount(Booking $booking): void
    {
        abort_unless($booking->provider_id === $this->provider()->id, 404);
        $this->bookingId = $booking->id;
    }

    private function job(): Booking
    {
        $booking = Booking::with([
            'service', 'address', 'customer:id,name,phone',
            'statusHistory' => fn ($q) => $q->orderBy('changed_at')->orderBy('id'),
        ])->findOrFail($this->bookingId);

        abort_unless($booking->provider_id === $this->provider()->id, 404);

        return $booking;
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
        $this->notice = 'Job completed.';
    }

    public function render()
    {
        $booking = $this->job();

        return view('livewire.provider.jobs.show', [
            'booking' => $booking,
            'stuckMinutes' => $this->stuckMinutes($booking),
            'isLive' => in_array($booking->status, ['assigned', 'in_progress'], true),
            'commission' => $booking->status === 'completed' ? $booking->commission()->first() : null,
        ])->layout('components.layouts.provider', ['title' => 'Job '.$booking->code]);
    }
}
