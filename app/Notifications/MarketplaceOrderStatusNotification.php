<?php

namespace App\Notifications;

use App\Models\MarketplaceOrder;
use App\Models\Setting;
use App\Notifications\Support\ChannelResolver;
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

    /** E-commerce Phase 1: tell the shop owner a new order is waiting (cash / wallet at once; online once paid). */
    public static function toSeller(MarketplaceOrder $order): void
    {
        $owner = $order->store?->provider?->user;
        if ($owner) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
            $owner->notify(new self('seller_new', $order, $channels));
        }
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
            // E-commerce Phase 1: to the seller (store owner), once the order is theirs to accept.
            'seller_new' => ['subject' => 'New order', 'body' => "New order {$this->order->code}: {$symbol}".number_format((float) $this->order->total_amount, 2).'. Open the seller portal to accept it.'],
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
