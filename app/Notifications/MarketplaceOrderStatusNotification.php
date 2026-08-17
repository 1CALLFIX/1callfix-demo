<?php

namespace App\Notifications;

use App\Models\MarketplaceOrder;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Phase 24 (Marketplace Foundation) — covers marketplace_order.{created,accepted,preparing,ready,completed,cancelled,refunded}, mirroring PropertyReservationStatusNotification's exact shape. */
class MarketplaceOrderStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $event,
        private MarketplaceOrder $order,
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
        return "marketplace_order.{$this->event}";
    }

    private function copy(): array
    {
        $symbol = Setting::get('locale.currency_symbol', '₹');

        return match ($this->event) {
            'created' => ['subject' => 'Order placed', 'body' => "Your order {$this->order->code} has been placed."],
            'accepted' => ['subject' => 'Order accepted', 'body' => "Your order {$this->order->code} has been accepted by the store."],
            'preparing' => ['subject' => 'Order being prepared', 'body' => "Your order {$this->order->code} is being prepared."],
            'ready' => ['subject' => 'Order ready', 'body' => "Your order {$this->order->code} is ready."],
            'rider_assigned' => ['subject' => 'Rider on the way', 'body' => "A delivery rider has been assigned to your order {$this->order->code}."],
            'completed' => ['subject' => 'Order completed', 'body' => "Your order {$this->order->code} is complete. Thank you for using 1CallFix."],
            'cancelled' => ['subject' => 'Order cancelled', 'body' => "Your order {$this->order->code} has been cancelled."],
            'refunded' => ['subject' => 'Refund processed', 'body' => "A refund of {$symbol}".number_format($this->amount ?? 0, 2)." for order {$this->order->code} has been processed."],
            default => ['subject' => 'Order update', 'body' => "Your order {$this->order->code} was updated."],
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
