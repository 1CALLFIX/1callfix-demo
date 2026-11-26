<?php

namespace App\Livewire\Partner;

use App\Services\Partners\IdCardService;
use Livewire\Component;

/**
 * Owner 2026-11-26: "My ID card" in the Partner app (service partners) and the Rider app (riders / drivers). Shows
 * what is missing in numbered steps (documents approved, then a face photo the franchise approves), then the card
 * front and back, "Download to print", and "I lost my card" (new QR; the old card stops working at once).
 * App\Livewire\Provider\IdCard and App\Livewire\Rider\IdCard pick the app.
 */
abstract class IdCardScreen extends Component
{
    public string $notice = '';

    /** provider | rider (IdCardService::TYPES) */
    abstract protected function type(): string;

    abstract protected function layout(): string;

    /** Lost card or expired: a new QR and a new date. */
    public function newCard(IdCardService $cards): void
    {
        $user = auth()->user();
        $holder = $cards->holder($user, $this->type());
        abort_unless($holder && $cards->state($holder) === 'ready', 403);

        $cards->renew($cards->cardFor($user, $holder));
        $this->notice = 'Your new card is ready. The old card does not work any more: print this one.';
    }

    public function render()
    {
        $cards = app(IdCardService::class);
        $user = auth()->user();
        $holder = $cards->holder($user, $this->type());
        abort_unless($holder, 404);

        $state = $cards->state($holder);
        $card = $state === 'ready' ? $cards->cardFor($user, $holder) : null;

        return view('livewire.partner.id-card', [
            'state' => $state,
            'status' => $card ? $cards->status($card, $holder) : null,
            'c' => $card ? $cards->data($card, $holder) : null,
            'expiredOn' => $card?->valid_until->format('j M Y'),
            'photoDoc' => $cards->photo($holder),
            'joinUrl' => route('partner.join').'#doc-'.($this->type() === 'rider' ? 'rider' : 'provider').'-'.IdCardService::PHOTO,
            'printUrl' => route($this->type().'.id-card.print'),
        ])->layout($this->layout(), ['title' => 'My ID card']);
    }
}
