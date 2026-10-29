<?php

namespace App\Notifications;

use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Services\Parcel\ParcelSettings;
use App\Services\Parcel\ParcelWaitingCharge;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 22.4 (Parcel) — covers parcel_order.{created,assigned,picked_up,
 * delivered,cancelled,refunded}, one event key, one ParcelOrder, per-event
 * copy below. Mirrors BookingStatusNotification's shape exactly (same
 * via()/eventKey()/toMail()/toSms()/toPush() structure) — deliberately a
 * separate class, not a generalized BookingStatusNotification accepting an
 * Orderable, for the same "no forced abstraction with a single real
 * consumer" reasoning CancellationService's own refundIfPaidForParcelOrder()
 * docblock explains. Combines what BookingStatusNotification and
 * PaymentStatusNotification separately cover for Service into one class
 * here, since Parcel's notification surface is smaller — $amount is only
 * ever set for the 'refunded' event, mirroring PaymentStatusNotification's
 * own optional-amount shape.
 */
class ParcelOrderStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $event,
        private ParcelOrder $order,
        private array $channels,
        private ?float $amount = null
    ) {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return "parcel_order.{$this->event}";
    }

    private function copy(): array
    {
        $symbol = Setting::get('locale.currency_symbol', '₹');

        return match ($this->event) {
            'created' => ['subject' => 'Parcel order confirmed', 'body' => "Your parcel order {$this->order->code} has been received. ".($this->order->isScheduled() ? "We'll send a rider shortly before your pickup time." : "We're finding a rider for you.")],
            'assigned' => ['subject' => 'Rider assigned', 'body' => "A rider has been assigned to your parcel order {$this->order->code}. Pickup OTP: {$this->order->pickup_otp}."],
            'en_route' => ['subject' => 'Rider on the way', 'body' => "Your rider is on the way to pick up parcel {$this->order->code}."],
            'sender_paid' => ['subject' => 'You paid the rider', 'body' => "The recipient did not pay for parcel {$this->order->code}, so you paid the rider the delivery charge on the spot. The parcel is being delivered."],
            'arrived' => ['subject' => 'Rider has arrived', 'body' => "Your rider has reached the pickup point for parcel {$this->order->code}. Please hand the parcel over.".$this->waitingNote()],
            'picked_up' => ['subject' => 'Parcel picked up', 'body' => "Your parcel {$this->order->code} has been picked up and is on its way."],
            'delivered' => ['subject' => 'Parcel delivered', 'body' => "Your parcel {$this->order->code} has been delivered. Thank you for using 1CallFix."],
            'cancelled' => ['subject' => 'Parcel order cancelled', 'body' => "Your parcel order {$this->order->code} has been cancelled."],
            // Pickup safety (2026-10-29): the rider refused the parcel at the pickup point.
            'refused' => ['subject' => 'Parcel not picked up', 'body' => "The rider could not take your parcel {$this->order->code}: "
                .(ParcelSettings::REFUSAL_REASONS[$this->order->refusal_reason]['customer'] ?? 'the rider could not safely accept it').'. The order is cancelled.'
                .((float) $this->order->cancellation_fee > 0 ? " A {$symbol}".number_format((float) $this->order->cancellation_fee, 2).' refusal fee is kept from your refund.' : '')],
            'refunded' => ['subject' => 'Refund processed', 'body' => "A refund of {$symbol}".number_format($this->amount ?? 0, 2)." for parcel order {$this->order->code} has been processed."],
            default => ['subject' => 'Parcel order update', 'body' => "Your parcel order {$this->order->code} was updated."],
        };
    }

    /** Stage 1: tells the sender about the waiting charge only where the admin has switched it on. */
    private function waitingNote(): string
    {
        $scope = ParcelWaitingCharge::scope($this->order);
        if (! ParcelSettings::waitingChargeEnabled($scope)) {
            return '';
        }
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $rate = rtrim(rtrim(number_format((float) ParcelSettings::get('parcel.waiting_charge_per_block', $scope), 2, '.', ''), '0'), '.');

        return ' The first '.ParcelSettings::get('parcel.waiting_free_minutes', $scope).' minutes of waiting are free, then '
            .$symbol.$rate.' for every '.ParcelSettings::get('parcel.waiting_block_minutes', $scope).' minutes, paid to the rider.';
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

    /** Push deep link to the customer's parcel tracking page (PushChannel reads this when present). */
    public function pushLink($notifiable): string
    {
        return route('customer.parcels.show', $this->order);
    }

    public function toPush($notifiable): array
    {
        $copy = $this->copy();

        return ['title' => $copy['subject'], 'body' => $copy['body']];
    }
}
