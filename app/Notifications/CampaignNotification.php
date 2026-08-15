<?php

namespace App\Notifications;

use App\Models\NotificationCampaign;
use App\Services\TemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The ONE notification class every Notification-Center send goes through —
 * promotions, meetings/reminders, app announcements, maintenance notices,
 * staff/vendor/rider communications alike. Content and channels come from
 * the Campaign row (composed once, sent to however many recipients);
 * per-recipient variables ({{name}}, {{phone}}) are substituted at render
 * time via TemplateRenderer, same as the transactional notifications'
 * per-event copy — this is the "one delivery architecture, two sources"
 * the corrected rule asked for, not a second notification system.
 *
 * Mission Phase 18 (performance/scale audit) finding: `use Queueable`
 * (needed for onQueue()/delay() to exist at all) was already present, but
 * the class never actually implemented ShouldQueue -- the one line that
 * turns it on -- so it stayed fully synchronous. CampaignService::send()
 * loops `$recipient->notify(new CampaignNotification(...))` over every
 * resolved recipient in-process; for a campaign sized at even a few
 * thousand recipients, that's a few thousand blocking SMS/push HTTP calls
 * inside a single Livewire admin request (NotificationCenter\Manage's own
 * "Send Now" action) or a single DispatchDueCampaigns scheduler tick --
 * a real request-timeout/scheduler-overrun risk, not a hypothetical one.
 * BookingStatusNotification and SubscriptionStatusNotification already
 * implement ShouldQueue; this brings the one bulk-fanout notification in
 * line with that same, already-established precedent.
 */
class CampaignNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private NotificationCampaign $campaign, private array $channels)
    {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return "campaign.{$this->campaign->type}";
    }

    public function campaignId(): int
    {
        return $this->campaign->id;
    }

    private function vars($notifiable): array
    {
        return [
            'name' => $notifiable->name ?? '',
            'phone' => $notifiable->phone ?? '',
        ];
    }

    private function renderedTitle($notifiable): string
    {
        return app(TemplateRenderer::class)->render($this->campaign->title, $this->vars($notifiable));
    }

    private function renderedBody($notifiable): string
    {
        return app(TemplateRenderer::class)->render($this->campaign->message, $this->vars($notifiable));
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->renderedTitle($notifiable))->line($this->renderedBody($notifiable));

        if ($this->campaign->action_url) {
            $mail->action('View', $this->campaign->action_url);
        }

        return $mail;
    }

    public function toSms($notifiable): string
    {
        return $this->renderedTitle($notifiable).' — '.$this->renderedBody($notifiable);
    }

    public function toPush($notifiable): array
    {
        return ['title' => $this->renderedTitle($notifiable), 'body' => $this->renderedBody($notifiable)];
    }

    /** Laravel's built-in 'database' channel — the "in-app" channel. */
    public function toArray($notifiable): array
    {
        return [
            'campaign_id' => $this->campaign->id,
            'type' => $this->campaign->type,
            'title' => $this->renderedTitle($notifiable),
            'body' => $this->renderedBody($notifiable),
            'image_url' => $this->campaign->image_url,
            'action_url' => $this->campaign->action_url,
        ];
    }
}
