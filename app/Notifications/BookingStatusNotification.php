<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Covers booking.created / booking.assigned / booking.en_route / booking.started /
 * booking.on_hold / booking.spares_available / booking.resumed / booking.completed /
 * booking.cancelled — one event key, one Booking, per-event copy below.
 * Sent synchronously (no ShouldQueue) so a test/verification run doesn't
 * depend on a queue worker actually running.
 */
class BookingStatusNotification extends Notification
{
    use Queueable;

    public function __construct(private string $event, private Booking $booking, private array $channels)
    {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return "booking.{$this->event}";
    }

    private function copy(): array
    {
        return match ($this->event) {
            'created' => ['subject' => 'Booking confirmed', 'body' => "Your booking {$this->booking->code} has been received. We're finding a provider for you."],
            'assigned' => ['subject' => 'Provider assigned', 'body' => "A provider has been assigned to your booking {$this->booking->code}."],
            // REF 1CF-SCHEDULING-DISPATCH-001 (Part 3) — the scheduled-
            // booking counterpart to 'assigned' above, naming the actual
            // day/time so "assigned" reads as confirmation of the
            // appointment, not just a generic status change.
            'scheduled_assigned' => (function () {
                $providerName = $this->booking->provider?->user?->name ?? 'A professional';
                $when = $this->booking->scheduled_at?->format('D, M j \a\t g:i A') ?? 'your scheduled time';

                return ['subject' => 'Provider assigned', 'body' => "{$providerName} has been assigned to your {$when} job ({$this->booking->code})."];
            })(),
            // Part "B" early-warning milestone (scheduled_at minus the
            // admin-configurable early-warning-hours) — fired only while
            // still unassigned, see ScheduledBookingEscalationService.
            'scheduled_still_searching' => ['subject' => 'Still finding your professional', 'body' => "We're still finding a professional for your booking {$this->booking->code}. We'll keep you posted."],
            // scheduled_at reached with nobody assigned, auto-cancelled
            // (dispatch.scheduled_auto_cancel_enabled = ON). Distinct
            // wording from 'no_provider_found' (the ASAP T+30 sweep) even
            // though the underlying cause is the same shape, because the
            // customer-facing timeline is completely different — this
            // booking sat open, accepting offers, right up to the moment
            // it was due.
            'scheduled_unassigned_cancelled' => ['subject' => 'Booking could not be completed', 'body' => "We couldn't find a professional in time for your scheduled booking {$this->booking->code}, and it has been cancelled. Any amount already paid has been refunded, and no cancellation fee applies. You're welcome to rebook."],
            'completed' => ['subject' => 'Booking completed', 'body' => "Your booking {$this->booking->code} is complete. Thank you for using 1CallFix."],
            'cancelled' => ['subject' => 'Booking cancelled', 'body' => "Your booking {$this->booking->code} has been cancelled."],
            // REF 1CF-IMPLEMENT-20260922-L01 — the T+30 platform auto-
            // cancellation (DispatchDeadlineSweepService). Distinct from
            // 'cancelled' because "has been cancelled" alone reads as
            // something the customer or provider did; this booking was
            // cancelled by the platform after it couldn't be matched.
            // Wording deliberately avoids asserting "no providers were
            // available" specifically — that would misrepresent a genuine
            // queue/job failure (see ServiceMatchingJob::failed()) as a
            // supply problem, so it stays true under either cause.
            'no_provider_found' => ['subject' => 'Booking could not be completed', 'body' => "Your booking {$this->booking->code} could not be matched to a provider in time and has been cancelled. Any amount already paid has been refunded, and no cancellation fee applies."],
            // REF 1CF-JOURNEY-001 — the middle of the job journey (see Support\Journey\StageNotifier).
            'en_route' => (function () {
                $name = $this->booking->provider?->user?->name ?? 'Your professional';

                return ['subject' => 'Your professional is on the way', 'body' => "{$name} is heading to you for booking {$this->booking->code}."];
            })(),
            'started' => ['subject' => 'Work has started', 'body' => "The job for booking {$this->booking->code} has started."],
            'on_hold' => (function () {
                $reason = \App\Support\Journey\JourneyCatalog::HOLD_REASONS[$this->booking->hold_reason ?? ''] ?? 'a short pause';

                return ['subject' => 'Your job is on hold', 'body' => "Booking {$this->booking->code} is on hold ({$reason}). We'll let you know as soon as work resumes."];
            })(),
            'spares_available' => ['subject' => 'Spare parts are ready', 'body' => "The spare parts for booking {$this->booking->code} are available. Work will resume shortly."],
            'extra_work_proposed' => (function () {
                $item = $this->booking->extraItems()->where('status', 'pending_approval')->latest('id')->first();
                $symbol = \App\Models\Setting::get('locale.currency_symbol', '₹');
                $what = $item ? "{$item->description} ({$symbol}".number_format((float) $item->amount, 2).')' : 'extra work';

                return ['subject' => 'Approval needed: extra work', 'body' => "Your professional found extra work on booking {$this->booking->code}: {$what}. Please approve or decline it in the app; the job is paused until you answer."];
            })(),
            'reassigned' => (function () {
                $name = $this->booking->provider?->user?->name ?? 'A new professional';

                return ['subject' => 'A new professional will continue your job', 'body' => "{$name} will continue the work on booking {$this->booking->code}. Your new start code is sent separately."];
            })(),
            // REF 1CF-CANCEL-POLICY-001
            'spares_declared' => (function () {
                $b = $this->booking;
                $date = $b->spares_expected_at?->format('j M Y') ?? 'a date to be confirmed';

                return ['subject' => 'Spare part update', 'body' => "Booking {$b->code} is waiting for a spare part (expected {$date}). Amount declared for the work done so far: ₹".number_format((float) $b->interim_amount, 2).". If this looks wrong you can dispute it from the booking page."];
            })(),
            'spares_early_unlock' => (function () {
                $b = $this->booking;
                $date = $b->spares_expected_at?->format('j M Y') ?? 'a later date';

                return ['subject' => 'You can cancel this booking', 'body' => "The spare part for booking {$b->code} is not expected until {$date}, which is longer than the allowed wait. You can keep waiting, or cancel now and pay only for the work already done."];
            })(),
            'spares_delay_warning' => (function () {
                $clock = app(\App\Services\Cancellation\SparesDelayClock::class);
                $days = $clock->thresholdDays($this->booking);

                return ['subject' => 'Parts still pending', 'body' => "The spare part for booking {$this->booking->code} is still pending. If the wait reaches {$days} days you can cancel and pay only for the work already done."];
            })(),
            'spares_cancel_unlocked' => ['subject' => 'You can now cancel', 'body' => "Booking {$this->booking->code} has been waiting for spare parts too long. You can now cancel it and pay only for the work already done, or keep waiting."],
            'spares_date_passed' => ['subject' => 'Spare part is late', 'body' => "The expected arrival date for booking {$this->booking->code}'s spare part has passed. We have asked your professional for a new date."],
            'spares_resume_overdue' => ['subject' => 'You can cancel free of charge', 'body' => "The spare part for booking {$this->booking->code} is ready but work has not resumed. You can cancel free of charge."],
            'extra_work_expired' => ['subject' => 'Extra work request expired', 'body' => "You did not respond to the extra-work request on booking {$this->booking->code} within the allowed time, so it was declined and the job continues at the original price."],
            'cancel_payment_due' => ['subject' => 'Pay the cancellation charge', 'body' => "To finish cancelling booking {$this->booking->code}, please pay the cancellation charge from the booking page. The booking stays as it is until you pay."],
            'interim_resolved' => ['subject' => 'Your dispute was reviewed', 'body' => "Our team has reviewed the progress figures you disputed on booking {$this->booking->code}. You can now continue with your cancellation or keep waiting."],
            'resumed' => ['subject' => 'Work has resumed', 'body' => "Work on booking {$this->booking->code} has resumed."],
            default => ['subject' => 'Booking update', 'body' => "Your booking {$this->booking->code} was updated."],
        };
    }

    public function toMail($notifiable): MailMessage
    {
        $copy = $this->copy();

        return (new MailMessage)->subject($copy['subject'])->line($copy['body']);
    }

    public function toSms($notifiable): string
    {
        return $this->copy()['body'];
    }

    public function toPush($notifiable): array
    {
        $copy = $this->copy();

        return ['title' => $copy['subject'], 'body' => $copy['body']];
    }
}
