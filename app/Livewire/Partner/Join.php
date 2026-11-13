<?php

namespace App\Livewire\Partner;

use App\Models\FieldWorker;
use App\Models\Provider;
use App\Services\Kyc\KycDocumentService;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * "Go live" (owner 2026-11-12: "every business should feel very easy to join"). A partner applies in about two
 * minutes (mobile code, name, business, address); this page is the one checklist for the rest, for every kind of
 * partner: 1. applied ✓  2. documents (upload here, any time)  3. set up (menu / products / rooms / services)
 * 4. approval. Approval still needs every required document (ReviewProviderKycAction / ReviewFieldWorkerKycAction);
 * documents go through the one KycDocumentService, so admins review them exactly as before.
 */
class Join extends Component
{
    use WithFileUploads;

    /** Staged uploads keyed "provider.id_proof" / "rider.id_proof". */
    public array $files = [];

    public string $notice = '';

    public string $error = '';

    /** Shown right under the "help me" button (it sits at the bottom of the page). */
    public string $helpNotice = '';

    public function mount(): void
    {
        abort_unless($this->provider() || $this->rider(), 404);
    }

    private function provider(): ?Provider
    {
        return auth()->user()->providerProfile()->with('franchise')->first();
    }

    private function rider(): ?FieldWorker
    {
        $fw = auth()->user()->fieldWorkerProfile()->with('franchise')->first();

        return $fw && $fw->hasCapability('parcel_rider') ? $fw : null;
    }

    /** Upload one document as soon as it is picked: no separate save button. */
    public function updatedFiles($value, string $key): void
    {
        $this->reset('notice', 'error');
        [$who, $type] = array_pad(explode('.', $key, 2), 2, '');
        $subject = $who === 'rider' ? $this->rider() : ($who === 'provider' ? $this->provider() : null);
        $kyc = app(KycDocumentService::class);
        $allowed = $subject ? $kyc->requirementsForSubject($subject, $subject->franchise?->country_id)->pluck('document_type') : collect();
        if (! $subject || ! $allowed->contains($type)) {
            $this->error = 'That document is not on your list.';

            return;
        }
        $this->validate(["files.$who.$type" => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:10240']], [], ["files.$who.$type" => 'document']);
        $missingBefore = count($kyc->missingRequiredForSubmission($subject, $subject->franchise?->country_id));
        try {
            $kyc->upload($subject, $type, $this->files[$who][$type], auth()->user(), 'self');
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }
        unset($this->files[$who][$type]);
        $this->notice = 'Uploaded. We check it and tell you if anything is wrong.';

        // The last required document just arrived: "We have your documents" (once — only on this transition).
        if ($missingBefore > 0 && count($kyc->missingRequiredForSubmission($subject, $subject->franchise?->country_id)) === 0) {
            $this->notice = 'All your documents are in. We are checking them now.';
            rescue(fn () => auth()->user()->notify(new \App\Notifications\KycNotification('documents_in',
                \App\Notifications\Support\ChannelResolver::resolve(array_filter(['franchise_id' => $subject->franchise_id])),
                $subject instanceof FieldWorker ? ['app' => 'rider'] : [])));
        }
    }

    /** @return list<array{key: string, title: string, subject: Provider|FieldWorker, rows: \Illuminate\Support\Collection, missing: int, status: string}> */
    private function groups(): array
    {
        $kyc = app(KycDocumentService::class);
        $out = [];
        foreach (['provider' => $this->provider(), 'rider' => $this->rider()] as $key => $subject) {
            if (! $subject) {
                continue;
            }
            $docs = $subject->currentDocuments()->get()->keyBy('type');
            $rows = $kyc->requirementsForSubject($subject, $subject->franchise?->country_id)
                ->map(fn ($req) => ['req' => $req, 'doc' => $docs->get($req->document_type)]);
            $out[] = [
                'key' => $key,
                'title' => $key === 'rider' ? 'Delivery rider' : 'Your business',
                'subject' => $subject,
                'rows' => $rows,
                'missing' => count($kyc->missingRequiredForSubmission($subject, $subject->franchise?->country_id)),
                'status' => (string) $subject->kyc_status,
            ];
        }

        return $out;
    }

    /**
     * "Help me join" (partners part B): the partner asks for a call; it lands in Admin → Partner leads as a new lead
     * ("Asked for a call"), where the team already calls applicants. One request a day is enough.
     */
    public function helpMe(): void
    {
        $this->reset('notice', 'error');
        $u = auth()->user();
        $role = match (true) {
            $u->holdsPartnerRole('hotel') => 'hotel', $u->holdsPartnerRole('events') => 'events', $u->holdsPartnerRole('food') => 'food', $u->holdsPartnerRole('commerce') => 'commerce',
            $u->holdsPartnerRole('parcel') && ! $u->providerProfile()->exists() => 'parcel', default => 'service',
        };
        $phone = \App\Support\PhoneNumber::national((string) $u->phone);
        $recent = \App\Models\PartnerLead::where('phone', $phone)->where('role', $role)->where('source', 'help_me_join')
            ->where('status', \App\Models\PartnerLead::STATUS_NEW)->where('updated_at', '>', now()->subDay())->exists();
        if (! $recent) {
            $franchise = $this->provider()?->franchise ?? $this->rider()?->franchise;
            $lead = \App\Models\PartnerLead::capture([
                'name' => (string) $u->name, 'phone' => $phone, 'city' => (string) ($franchise?->city?->name ?? ''), 'role' => $role,
                'status' => \App\Models\PartnerLead::STATUS_NEW, 'consent_text' => 'Asked for a call from Go live', 'acquisition' => null, 'source' => 'help_me_join',
            ]);
            // A converted lead asking again is a fresh request for the team to call.
            $lead->forceFill(['status' => \App\Models\PartnerLead::STATUS_NEW, 'source' => 'help_me_join'])->save();
        }
        $this->helpNotice = 'Thank you. Our team will call you on '.$u->phone.' to help you go live.';
    }

    /** "3. Set up" is done once customers would see something: a room with a price, a product or dish, or services. */
    private function setUpDone(): bool
    {
        $u = auth()->user();
        $p = $this->provider();
        $done = [];
        if ($u->holdsPartnerRole('hotel')) {
            $done[] = \App\Models\HotelRoomType::whereHas('accommodation', fn ($q) => $q->where('provider_id', $p?->id))->where('is_active', true)->whereHas('ratePlans', fn ($q) => $q->where('is_active', true))->exists();
        }
        if ($u->holdsPartnerRole('events')) {
            $done[] = \App\Models\Event::whereHas('organiser', fn ($q) => $q->where('provider_id', $p?->id))->whereHas('ticketTypes')->exists();
        }
        if ($u->holdsPartnerRole('commerce')) {
            $done[] = \App\Models\Product::whereHas('store', fn ($q) => $q->where('provider_id', $p?->id))->where('is_active', true)->exists();
        }
        if ($u->holdsPartnerRole('service')) {
            $done[] = ! empty($p?->skills);
        }

        return $done === [] || ! in_array(false, $done, true);
    }

    /** Where this partner sets up what customers will see. @return list<array{label: string, url: string}> */
    private function setUpLinks(): array
    {
        $u = auth()->user();
        $links = [];
        if ($u->holdsPartnerRole('hotel')) {
            $links[] = ['label' => 'Add your rooms, prices and photos', 'url' => route('hotel.rooms')];
            $links[] = ['label' => 'Hotel details, check-in times and facilities', 'url' => route('hotel.details')];
        }
        if ($u->holdsPartnerRole('events')) {
            $links[] = ['label' => 'Create your first event and its tickets', 'url' => route('organiser.events')];
            $links[] = ['label' => 'Organiser details', 'url' => route('organiser.details')];
        }
        if ($u->holdsPartnerRole('food')) {
            $links[] = ['label' => 'Add your menu', 'url' => route('seller.products')];
        } elseif ($u->holdsPartnerRole('commerce')) {
            $links[] = ['label' => 'Add your products', 'url' => route('seller.products')];
        }
        if ($u->holdsPartnerRole('service')) {
            $links[] = ['label' => 'Open your partner dashboard', 'url' => route('provider.dashboard')];
        }
        if ($u->holdsPartnerRole('parcel')) {
            $links[] = ['label' => 'Open the rider app', 'url' => route('rider.dashboard')];
        }

        return $links;
    }

    public function render()
    {
        $groups = $this->groups();
        $allDocs = collect($groups)->every(fn ($g) => $g['missing'] === 0);
        $approved = collect($groups)->every(fn ($g) => $g['status'] === 'approved');
        $setUp = $this->setUpDone();

        return view('livewire.partner.join', [
            'groups' => $groups,
            'links' => $this->setUpLinks(),
            'allDocs' => $allDocs,
            'approved' => $approved,
            'setUp' => $setUp,
            'done' => 1 + (int) $allDocs + (int) $setUp + (int) $approved,
        ])->layout($this->portalLayout(), ['title' => 'Go live']);
    }

    /** The partner's own portal frame (hotel, shop / restaurant, rider), never another role's tabs. */
    private function portalLayout(): string
    {
        $u = auth()->user();

        return match (true) {
            $u->holdsPartnerRole('hotel') && ! $u->holdsPartnerRole('service') => 'components.layouts.hotel',
            $u->holdsPartnerRole('events') && ! $u->holdsPartnerRole('service') && ! $u->holdsPartnerRole('commerce') => 'components.layouts.organiser',
            $u->holdsPartnerRole('commerce') && ! $u->holdsPartnerRole('service') => 'components.layouts.seller',
            $u->holdsPartnerRole('parcel') && ! $u->providerProfile()->exists() => 'components.layouts.rider',
            default => 'components.layouts.provider',
        };
    }
}
