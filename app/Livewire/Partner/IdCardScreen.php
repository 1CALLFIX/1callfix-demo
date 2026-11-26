<?php

namespace App\Livewire\Partner;

use App\Services\Partners\IdCardService;
use Livewire\Component;

/**
 * Owner 2026-11-26: "My ID card" in the Partner app (service partners) and the Rider app (riders / drivers). Shows
 * what is missing in numbered steps (documents approved, a face photo the franchise approves, then blood group and
 * emergency contact numbers for the back), then the card front and back, "Download to print", "Change my details"
 * and "I lost my card" (new QR; the old card stops working at once).
 * App\Livewire\Provider\IdCard and App\Livewire\Rider\IdCard pick the app.
 */
abstract class IdCardScreen extends Component
{
    public string $notice = '';

    /** Back of the card: blood group (IdCardService::BLOOD_GROUPS key) and one or two family numbers. */
    public string $bloodGroup = '';

    /** @var list<array{relation: string, phone: string}> */
    public array $contacts = [['relation' => '', 'phone' => ''], ['relation' => '', 'phone' => '']];

    /** Showing the details form on a finished card ("Change my details"). */
    public bool $editing = false;

    /** provider | rider (IdCardService::TYPES) */
    abstract protected function type(): string;

    abstract protected function layout(): string;

    public function mount(): void
    {
        $cards = app(IdCardService::class);
        $holder = $cards->holder(auth()->user(), $this->type());
        if ($holder && $cards->state($holder) === 'ready') {
            $card = $cards->cardFor(auth()->user(), $holder);
            $this->bloodGroup = (string) $card->blood_group;
            foreach (array_slice((array) $card->emergency_contacts, 0, 2) as $i => $c) {
                $this->contacts[$i] = ['relation' => (string) ($c['relation'] ?? ''), 'phone' => (string) ($c['phone'] ?? '')];
            }
        }
    }

    public function saveDetails(IdCardService $cards): void
    {
        $user = auth()->user();
        $holder = $cards->holder($user, $this->type());
        abort_unless($holder && $cards->state($holder) === 'ready', 403);

        // Digits only, without +91 / 0 in front: "+91 98765-43210" → 9876543210.
        foreach ($this->contacts as $i => $c) {
            $digits = preg_replace('/\D/', '', (string) $c['phone']);
            $this->contacts[$i]['phone'] = strlen($digits) > 10 ? substr($digits, -10) : $digits;
        }

        $this->validate([
            'bloodGroup' => ['required', 'in:'.implode(',', array_keys(IdCardService::BLOOD_GROUPS))],
            'contacts.0.phone' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'contacts.0.relation' => ['required', 'in:'.implode(',', IdCardService::RELATIONS)],
            'contacts.1.phone' => ['nullable', 'regex:/^[6-9]\d{9}$/', 'different:contacts.0.phone'],
            'contacts.1.relation' => ['required_with:contacts.1.phone', 'nullable', 'in:'.implode(',', IdCardService::RELATIONS)],
        ], [
            'bloodGroup.required' => 'Tap your blood group.',
            'contacts.0.phone.required' => 'Type one family number for emergencies.',
            'contacts.*.phone.regex' => 'Type a 10-digit mobile number.',
            'contacts.0.relation.required' => 'Tap who this number belongs to.',
            'contacts.1.relation.required_with' => 'Tap who this number belongs to.',
            'contacts.1.phone.different' => 'This is the same number as the first one.',
        ]);

        $contacts = array_values(array_filter($this->contacts, fn ($c) => $c['phone'] !== ''));
        $cards->saveDetails($cards->cardFor($user, $holder), $this->bloodGroup, $contacts);
        $this->editing = false;
        $this->notice = 'Saved. Your details are on the back of your card.';
    }

    /** Lost card or expired: a new QR and a new date. Blood group and emergency numbers stay. */
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
            'needsDetails' => $card && ($this->editing || ! $cards->detailsComplete($card)),
            'firstTime' => $card && ! $cards->detailsComplete($card),
            'status' => $card ? $cards->status($card, $holder) : null,
            'c' => $card ? $cards->data($card, $holder, private: true) : null,
            'expiredOn' => $card?->valid_until->format('j M Y'),
            'photoDoc' => $cards->photo($holder),
            'joinUrl' => route('partner.join').'#doc-'.($this->type() === 'rider' ? 'rider' : 'provider').'-'.IdCardService::PHOTO,
            'printUrl' => route($this->type().'.id-card.print'),
        ])->layout($this->layout(), ['title' => 'My ID card']);
    }
}
