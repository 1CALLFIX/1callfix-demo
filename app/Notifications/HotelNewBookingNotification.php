<?php

namespace App\Notifications;

use App\Models\HotelReservation;
use App\Notifications\Channels\PushChannel;
use App\Support\DispatchQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Hotels H3 — a paid booking reached the hotel. In the Partner app it rings like a job offer when the hotel must accept
 * it in time (kind 'offer'), otherwise it is a normal alert (already confirmed from the calendar). Opens the hotel's
 * Bookings tab.
 */
class HotelNewBookingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private HotelReservation $reservation, private array $channels, private bool $mustAccept)
    {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function viaQueues(): array
    {
        return [PushChannel::class => DispatchQueue::name()];
    }

    public function eventKey(): string
    {
        return 'hotel.new_booking';
    }

    public function pushApp(): string
    {
        return 'partner';
    }

    public function pushKind(): string
    {
        return $this->mustAccept ? 'offer' : 'general';
    }

    public function pushLink($notifiable): string
    {
        return route('hotel.bookings');
    }

    private function copy(): array
    {
        $r = $this->reservation;
        $stay = $r->check_in_date->format('j M').' → '.$r->check_out_date->format('j M').", {$r->number_of_rooms} room(s), {$r->number_of_adults} adult(s)";

        return $this->mustAccept
            ? ['subject' => 'New booking: accept now', 'body' => "Booking {$r->code}: {$stay}. Paid. Accept it in the hotel app before ".app(\App\Services\TimezoneResolver::class)->format($r->accept_by, $r->franchise, 'g:i A').' or it is cancelled.']
            : ['subject' => 'New booking', 'body' => "Booking {$r->code}: {$stay}. Paid and confirmed."];
    }

    public function toMail($notifiable): MailMessage
    {
        $copy = $this->copy();

        return (new MailMessage)->subject($copy['subject'])->line($copy['body'])->action('Open bookings', $this->pushLink($notifiable));
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
