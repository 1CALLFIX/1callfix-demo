<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

/**
 * Real-time operational visibility for admins who opt in
 * (users.push_ops_alerts) on the dashboard toggle. Fired by
 * App\Services\AdminOpsAlertService on two events Mohammed asked to see:
 *
 *   booking_created   — any booking, any status, any creation path
 *   payment_captured  — any Razorpay capture (booking, bundle, wallet
 *                       top-up, plan subscription)
 *
 * Push-only in practice: toMail()/toSms() are intentionally absent so that
 * even though ChannelResolver may return ['mail', PushChannel] this
 * notification only materialises on the push channel. Not a Notification
 * Center broadcast — see the migration docblock for the scoping rationale.
 */
class AdminOpsAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private string $event, private Model $subject, private array $channels)
    {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return "admin.ops_{$this->event}";
    }

    public function pushLink($notifiable): string
    {
        if ($this->subject instanceof Booking) {
            return route('admin.bookings.show', $this->subject->id);
        }

        if ($this->subject instanceof Payment && $this->subject->booking_id) {
            return route('admin.bookings.show', $this->subject->booking_id);
        }

        return route('admin.payments.index');
    }

    public function toPush($notifiable): array
    {
        return match ($this->event) {
            'booking_created' => $this->bookingCreatedCopy(),
            'payment_captured' => $this->paymentCapturedCopy(),
            default => ['title' => 'Operations update', 'body' => 'An operational event occurred.'],
        };
    }

    private function bookingCreatedCopy(): array
    {
        /** @var Booking $b */
        $b = $this->subject;
        $service = $b->service?->name ?? 'Service';
        $zone = $b->zone?->name ? " · {$b->zone->name}" : '';

        return [
            'title' => 'New booking',
            'body' => "Booking {$b->code} — {$service}{$zone} ({$b->status}).",
        ];
    }

    private function paymentCapturedCopy(): array
    {
        /** @var Payment $p */
        $p = $this->subject;
        $amount = '₹'.number_format((float) $p->amount, 2);
        $purpose = str_replace('_', ' ', (string) $p->purpose);
        $ref = $p->booking?->code ? " for {$p->booking->code}" : '';

        return [
            'title' => 'Payment captured',
            'body' => "{$amount} captured{$ref} ({$purpose}).",
        ];
    }
}
