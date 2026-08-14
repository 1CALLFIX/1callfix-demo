<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivers the Service booking start_otp/completion_otp to the CUSTOMER —
 * closing the one real gap AUTH_FORENSIC_DISCOVERY.md found: both codes
 * were generated and verified correctly but never actually reached the
 * customer by any channel. Sent to a real $booking->customer User (already
 * Notifiable, already has routeNotificationForSms()/routeNotificationForPush() —
 * unlike the login OTP's ad-hoc-notifiable case, no framework subtlety
 * here), through the SAME SmsChannel/PushChannel this app already uses for
 * BookingStatusNotification/PaymentStatusNotification — not a new
 * notification system.
 *
 * Sent synchronously (no ShouldQueue), matching BookingStatusNotification's
 * own reasoning: a test/verification run shouldn't depend on a queue
 * worker actually running.
 */
class BookingOtpNotification extends Notification
{
    use Queueable;

    /** @param string $type 'start'|'completion' */
    public function __construct(private Booking $booking, private string $type, private string $code, private array $channels)
    {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    /** otps.purpose's own established vocabulary ("login, booking_start, booking_complete") — reused for consistency, not a new taxonomy. */
    public function purpose(): string
    {
        return $this->type === 'start' ? 'booking_start' : 'booking_complete';
    }

    private function copy(): array
    {
        $label = $this->type === 'start' ? 'start' : 'completion';
        $instruction = $this->type === 'start'
            ? 'Share this code with your service provider ONLY when they arrive to begin work.'
            : 'Share this code with your service provider ONLY once the work is fully complete and you are satisfied.';

        return [
            'subject' => ucfirst($label).' code for your booking',
            'body' => "Your 1CallFix {$label} code for booking {$this->booking->code} is {$this->code}. {$instruction} Do not share it with anyone else.",
        ];
    }

    /**
     * Without this, the DEFAULT (unconfigured) notifications.channels
     * Setting is 'mail' (ChannelResolver::resolve()'s own default) -- every
     * franchise/zone that has never visited Settings > Notifications gets
     * exactly that default. A notification whose via() can return ['mail']
     * but has no toMail() throws "Call to undefined method toMail()" the
     * instant Laravel's ChannelManager tries to build the mail message --
     * caught by AcceptBookingAction/AdminReassignBookingAction's own
     * try/catch and only logged, so the OTP silently fails to deliver on
     * the very default this app ships with. Found by
     * BookingOtpDeliveryTest's real-channel-pipeline test (Notification::fake()
     * alone can't catch this -- it never calls via()/toMail() at all).
     * Matches BookingStatusNotification's own toMail() exactly.
     */
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
