<?php

namespace App\Notifications;

use App\Models\MarketplaceOrder;
use App\Notifications\Channels\PushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Restaurants with no billing software (owner 2026-11-12): a paid food order nobody has accepted yet after
 * food.nudge_after_minutes reaches the owner's phone (partner-app push, plus the platform channels — email, SMS or
 * WhatsApp when switched on) before food:expire-unaccepted would cancel it. Sent once per order.
 */
class FoodOrderWaitingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<string>  $channels */
    public function __construct(private MarketplaceOrder $order, private array $channels, private int $minutesLeft)
    {
    }

    public function via($notifiable): array
    {
        return array_values(array_unique([PushChannel::class, ...$this->channels]));
    }

    public function eventKey(): string
    {
        return 'food.order_waiting';
    }

    private function line(): string
    {
        return "New order {$this->order->code} is waiting. Accept it in the next {$this->minutesLeft} min or it is cancelled.";
    }

    public function pushLink($notifiable): string
    {
        return route('seller.orders');
    }

    public function toPush($notifiable): array
    {
        return ['title' => 'Order waiting: '.$this->order->code, 'body' => $this->line()];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)->subject('Order waiting: '.$this->order->code)->line($this->line())->action('Open orders', route('seller.orders'));
    }

    public function toSms($notifiable): string
    {
        return '1CallFix: '.$this->line();
    }

    public function toWhatsApp($notifiable): string
    {
        return $this->toSms($notifiable);
    }
}
