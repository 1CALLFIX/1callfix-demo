<?php

namespace App\Notifications;

use App\Models\HallBooking;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Function halls R2 — hall_booking.{received, confirmed, declined} to the customer and hall_booking.owner_new to the
 * hall owner ("accept within N hours", so a push reaches the owner with the portal closed). Same shape as the hotel and
 * event notifications.
 */
class HallBookingNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $event,
        private HallBooking $booking,
        private array $channels,
        private ?float $amount = null,
    ) {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return "hall_booking.{$this->event}";
    }

    private function copy(): array
    {
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $b = $this->booking;
        $hall = $b->hall?->name ?? 'the hall';
        $when = $b->dateLabel().' ('.$b->slot_name.')';
        $money = $symbol.number_format($this->amount ?? 0, 2);
        $tz = app(\App\Services\TimezoneResolver::class)->timezoneFor($b->hall?->franchise);
        $by = $b->accept_by?->copy()->setTimezone($tz)->format('g:i A');

        return match ($this->event) {
            'received' => ['subject' => 'Hall booking paid: waiting for the hall', 'body' => "We received your payment for {$hall} on {$when} (booking {$b->code}). The hall confirms by {$by}; if it cannot, you get all your money back."],
            'confirmed' => ['subject' => 'Your hall is booked', 'body' => "{$hall} is booked for you on {$when} (booking {$b->code}). Open My hall bookings for the details."],
            'declined' => ['subject' => 'Hall not available: full refund', 'body' => "{$hall} could not take your booking for {$when} (booking {$b->code}). {$money} is being refunded to where you paid from."],
            'balance_due' => ['subject' => 'Hall booking: balance due', 'body' => "Please pay the balance of {$money} for {$hall} on {$when} (booking {$b->code}) by ".($b->balance_due_on?->format('j M Y') ?? 'the due date').'. Open My hall bookings and tap Pay balance.'],
            'balance_paid' => ['subject' => 'Balance received', 'body' => "We received {$money} for {$hall} on {$when} (booking {$b->code}). You are all set."],
            'cancelled' => ['subject' => 'Hall booking cancelled', 'body' => "Your booking {$b->code} for {$hall} on {$when} is cancelled. {$money} is being refunded to where you paid from."],
            'hall_cancelled' => ['subject' => 'The hall cancelled your booking: full refund', 'body' => "{$hall} cancelled your booking for {$when} (booking {$b->code}). All your money, {$money}, is being refunded to where you paid from."],
            'deposit_returned' => ['subject' => 'Deposit returned', 'body' => "Your deposit of {$money} for {$hall} (booking {$b->code}) is being refunded to where you paid from. Thank you!"],
            'owner_cancelled' => ['subject' => 'A hall booking was cancelled', 'body' => "The booking {$b->code} for {$hall} on {$when} ({$b->customer_name}) was cancelled. The date is open again."],
            'deposit_settled' => ['subject' => 'Your deposit after the damage check', 'body' => "Our team checked the damage the hall reported for booking {$b->code}. {$money} of your deposit is being refunded to where you paid from."],
            'owner_damage_settled' => ['subject' => 'Damage claim decided', 'body' => "Our team decided your damage claim for booking {$b->code}: {$money} goes to your earnings."],
            'owner_new' => ['subject' => 'New hall booking: please accept', 'body' => "New booking for {$hall} on {$when}: {$b->customer_name}".($b->occasion ? ", {$b->occasion}" : '').". Accept or decline by {$by} in your hall portal, or it is declined and refunded."],
            default => ['subject' => 'Hall booking update', 'body' => "Your booking {$b->code} for {$hall} was updated."],
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

    /** Tapping the push opens the owner's bookings or the customer's booking page. */
    public function pushLink($notifiable): string
    {
        return str_starts_with($this->event, 'owner_') ? route('venue.bookings') : route('customer.halls.booking', $this->booking->code);
    }
}
