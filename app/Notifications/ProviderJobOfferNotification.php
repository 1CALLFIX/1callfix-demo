<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The job OFFER — sent to every candidate provider the moment
 * ServiceMatchingJob (or BundleConsolidationJob) creates their
 * dispatch_attempts row, so a provider hears a new job even with the
 * partner web app fully closed / phone locked. This is the FCM half of
 * what Phase PN1 could only do foreground; the `NewJobOffered` broadcast
 * still covers the app-open case.
 *
 * Distinct from ProviderJobStatusNotification, which starts at `assigned`
 * — i.e. AFTER the provider has accepted. Same pipeline otherwise:
 * via()/ChannelResolver/PushChannel/notification_logs, Notifiable is the
 * provider's own User (Provider has no fcm_token column).
 *
 * implements ShouldQueue — it runs inside ServiceMatchingJob's candidate
 * loop; each FCM HTTP call becomes its own queue job rather than blocking
 * the dispatch round (same precedent as CampaignNotification).
 */
class ProviderJobOfferNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Booking $booking, private array $channels)
    {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return 'provider.job_offered';
    }

    /**
     * Deep link read by public/firebase-messaging-sw.js for the notification's click action.
     *
     * The OFFERS page, not provider.jobs.show: an offer is only a
     * dispatch_attempts row, and bookings.provider_id stays null until the
     * provider accepts — Jobs\Show 404s for any booking that isn't already
     * theirs. Jobs\Index is where a live offer is actually answered, and it
     * uses `offer` to explain an expired/taken offer and to forward an
     * already-accepted one to the job page. Ownership is never read from
     * this URL; Index only ever queries the signed-in provider's own rows.
     */
    public function pushLink($notifiable): string
    {
        return route('provider.jobs.index', ['offer' => $this->booking->id]);
    }

    private function copy(): array
    {
        $code = $this->booking->code;
        $service = $this->booking->service?->name ?? 'a job';
        $price = $this->booking->price_quoted;
        $priceLabel = $price !== null ? ' · ₹'.number_format((float) $price, 2) : '';

        return [
            'subject' => 'New job offer',
            'body' => "New job offer {$code} — {$service}{$priceLabel}. Open the partner app to accept before it expires.",
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $copy = $this->copy();

        return (new MailMessage)->subject($copy['subject'])->line($copy['body'])
            ->action('Open job offer', $this->pushLink($notifiable));
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
