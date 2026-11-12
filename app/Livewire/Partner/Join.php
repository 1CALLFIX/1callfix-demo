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
        $allowed = $subject ? $kyc->requirementsFor($subject instanceof Provider ? 'provider' : 'field_worker', $subject->franchise?->country_id)->pluck('document_type') : collect();
        if (! $subject || ! $allowed->contains($type)) {
            $this->error = 'That document is not on your list.';

            return;
        }
        $this->validate(["files.$who.$type" => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:10240']], [], ["files.$who.$type" => 'document']);
        try {
            $kyc->upload($subject, $type, $this->files[$who][$type], auth()->user(), 'self');
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }
        unset($this->files[$who][$type]);
        $this->notice = 'Uploaded. We check it and tell you if anything is wrong.';
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
            $rows = $kyc->requirementsFor($key === 'rider' ? 'field_worker' : 'provider', $subject->franchise?->country_id)
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

    /** Where this partner sets up what customers will see. @return list<array{label: string, url: string}> */
    private function setUpLinks(): array
    {
        $u = auth()->user();
        $links = [];
        if ($u->holdsPartnerRole('hotel')) {
            $links[] = ['label' => 'Add your rooms, prices and photos', 'url' => route('hotel.rooms')];
            $links[] = ['label' => 'Hotel details, check-in times and facilities', 'url' => route('hotel.details')];
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

        return view('livewire.partner.join', [
            'groups' => $groups,
            'links' => $this->setUpLinks(),
            'allDocs' => collect($groups)->every(fn ($g) => $g['missing'] === 0),
            'approved' => collect($groups)->every(fn ($g) => $g['status'] === 'approved'),
        ])->layout($this->portalLayout(), ['title' => 'Go live']);
    }

    /** The partner's own portal frame (hotel, shop / restaurant, rider), never another role's tabs. */
    private function portalLayout(): string
    {
        $u = auth()->user();

        return match (true) {
            $u->holdsPartnerRole('hotel') && ! $u->holdsPartnerRole('service') => 'components.layouts.hotel',
            $u->holdsPartnerRole('commerce') && ! $u->holdsPartnerRole('service') => 'components.layouts.seller',
            $u->holdsPartnerRole('parcel') && ! $u->providerProfile()->exists() => 'components.layouts.rider',
            default => 'components.layouts.provider',
        };
    }
}
