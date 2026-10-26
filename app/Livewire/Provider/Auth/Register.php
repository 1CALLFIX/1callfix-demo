<?php

namespace App\Livewire\Provider\Auth;

use App\Actions\CreateParcelRiderAction;
use App\Actions\RegisterProviderAction;
use App\Contracts\FirebaseTokenVerifier;
use App\Exceptions\AccountAlreadyExistsException;
use App\Exceptions\FirebaseAuthException;
use App\Livewire\Customer\Auth\Concerns\InteractsWithAuthThrottle;
use App\Models\User;
use App\Models\Zone;
use App\Services\Customer\CustomerLocationContext;
use App\Services\Catalog\ServiceCatalogQuery;
use App\Services\DispatchService;
use App\Services\Kyc\KycDocumentService;
use App\Support\PhoneNumber;
use App\Support\SiteControls;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * PHASE PSR — public, unauthenticated provider self-registration.
 *
 * Structural sibling of App\Livewire\Customer\Auth\Signup: the same
 * #[Locked] $step machine, the same Firebase phone-OTP sub-flow, the same
 * InteractsWithAuthThrottle (Livewire actions all share /livewire/update,
 * so route-level throttling never sees them). It is NOT a second
 * onboarding engine — the account write is the shared
 * RegisterProviderAction the CSV importer also calls, and documents go
 * through the existing KycDocumentService against requirement rows from
 * `kyc_document_requirements`. Nothing here can approve a provider; the
 * row lands at kyc_status = 'pending' for the same admin review queue a
 * CSV-imported provider goes through.
 *
 * Trust boundary: the browser only ever posts a Firebase ID token; it is
 * re-verified server-side and the resulting E.164 number is held in a
 * #[Locked] property. Submit refuses unless that is set.
 *
 * Confirmed decisions: users.status = active (D7); password is set at
 * signup; an out-of-coverage pin queues for manual placement, never blocks
 * (D3) — and neither does a missing pin: a typed work address alone is
 * enough, the row is flagged out-of-coverage for an operator to place; the
 * optional email is stored unverified (D4); the applicant picks the
 * service categories they offer (D2 reversed by the owner 2026-10-15 —
 * required / optional / hidden is a Super Admin setting, SiteControls);
 * no applicant notification (D8); no existing-customer -> provider
 * attach (D10) — a taken phone is refused with "sign in instead".
 */
class Register extends Component
{
    use InteractsWithAuthThrottle;
    use WithFileUploads;

    /**
     * Display state of the phone/OTP widget at the top of the one-page form
     * only — NOT a gate on the rest of the form, which is always visible and
     * fillable. phone = number entry; verify_phone = code entry; details =
     * number proven, widget shows a "verified" confirmation. Submit is gated
     * on verifiedPhoneE164, never on this.
     */
    #[Locked]
    public string $step = 'phone';

    public string $phone = '';

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $email = '';

    public string $address = '';

    public bool $terms = false;

    /** Service category ids the applicant offers — saved to providers.skills (what dispatch matches on). */
    public array $skills = [];

    /** Staged uploads keyed by kyc_document_requirements.document_type. */
    public array $documents = [];

    public string $error = '';

    public string $status = '';

    public bool $submitted = false;

    #[Locked]
    public string $verifiedPhoneE164 = '';

    #[Locked]
    public string $phoneFirebaseUid = '';

    #[Locked]
    public ?float $lat = null;

    #[Locked]
    public ?float $lng = null;

    #[Locked]
    public ?int $resolvedZoneId = null;

    #[Locked]
    public ?string $resolvedZoneName = null;

    #[Locked]
    public bool $outOfCoverage = false;

    /** REF 1CF-PARTNER-PAGE-001: the /partners lead this sign-up continues (id only; its name and phone only prefill the form — the phone is still OTP-verified). */
    #[Locked]
    public ?int $leadId = null;

    /**
     * Which partner this sign-up makes (owner rule 2026-10-25: every partner joins through /partners, then this one
     * page — OTP, documents, admin approval). 'service' = a provider (the default, and the only role reachable without
     * a /partners hand-off); 'parcel' = a delivery rider (a field worker with the parcel_rider capability, documents
     * from the `field_worker` requirement list, no services step). Set only in mount() from the server-side session.
     */
    #[Locked]
    public string $partnerRole = 'service';

    /** Partner roles this page can finish. */
    private const SIGNUP_ROLES = ['service', 'parcel'];

    public function mount(): void
    {
        $lead = session('partner_lead');
        if (is_array($lead) && in_array($lead['role'] ?? null, self::SIGNUP_ROLES, true) && is_int($lead['id'] ?? null)
            && $this->roleTakesSignUps($lead['role'])) {
            $this->leadId = $lead['id'];
            $this->partnerRole = $lead['role'];
            // Prefill the name and mobile typed on /partners so the applicant does not type them twice. Read from the
            // lead row by its server-held id, never from the browser; the number is still proven by OTP before submit.
            $row = \App\Models\PartnerLead::query()->where('id', $lead['id'])->where('role', $this->partnerRole)->first(['name', 'phone']);
            if ($row !== null) {
                $this->name = (string) $row->name;
                $this->phone = (string) $row->phone;
            }
            // A listed city's slug prefills the address (its stored name); a typed city sends no slug and prefills
            // nothing. The applicant confirms or replaces it, and the pin still decides the service area.
            $slug = (string) ($lead['city'] ?? '');
            if (preg_match('/^[a-z0-9-]{1,80}$/', $slug) === 1) {
                $name = \App\Livewire\Partner\ApplyForm::citySlugColumnExists() ? \App\Models\City::query()->where('slug', $slug)->value('name') : null;
                $this->address = (string) ($name ?? \Illuminate\Support\Str::headline($slug));
            }
        }

        if (auth()->guard('web')->check()) {
            $user = auth()->user();
            $this->redirectRoute(
                $user->providerProfile()->exists() ? 'provider.dashboard'
                    : ($user->fieldWorkerProfile?->hasCapability('parcel_rider') ? 'rider.dashboard' : 'customer.home'),
                navigate: true,
            );
        }
    }

    public function isRider(): bool
    {
        return $this->partnerRole === 'parcel';
    }

    /** The /partners card for this role still hands off to sign-up (it can close again, e.g. parcel prices cleared). */
    private function roleTakesSignUps(string $role): bool
    {
        return (bool) (collect(\App\Support\PartnerPage\PartnerPageData::roles())->firstWhere('code', $role)['hands_off'] ?? false);
    }

    /** kyc_document_requirements.applicable_type for this sign-up. */
    private function documentsFor(): string
    {
        return $this->isRider() ? 'field_worker' : 'provider';
    }

    // ─────────────────────────── Phone step ──────────────────────────────

    public function requestPhoneCode(): void
    {
        $this->reset('error', 'status');

        $this->validate([
            'phone' => ['required', 'string', 'min:6', 'max:20', 'regex:/^[0-9+][0-9 \-]*$/'],
        ], ['phone.regex' => 'Enter a valid mobile number.']);

        if (! PhoneNumber::looksValid($this->phone)) {
            $this->addError('phone', 'Enter a valid 10-digit mobile number.');

            return;
        }

        // Cheap pre-send guard: a number that already belongs to an account
        // is refused by phoneTokenReceived() anyway (and by
        // RegisterProviderAction after that), but only once a full Firebase
        // OTP round-trip has been spent to reach it. Check the typed number
        // here, before any SMS goes out, so an already-registered applicant
        // is told to sign in immediately. Same national-digit shape those
        // later checks use.
        if (User::where('phone', PhoneNumber::national($this->phone))->exists()) {
            $this->addError('phone', 'An account with this mobile number already exists. Please sign in instead.');

            return;
        }

        if ($this->isThrottled('provider-reg-phone', $this->phone, maxPerIdentifier: SiteControls::otpPerMobile())) {
            return;
        }
        $this->hitThrottle('provider-reg-phone', $this->phone, SiteControls::windowSeconds());

        // Ask the browser to run Firebase signInWithPhoneNumber. The step
        // advances to code entry only once the SMS has really gone out
        // (#[On('firebase-phone-otp-sent')]); a failure comes back as
        // 'firebase-error' and leaves the user here with one honest error
        // instead of a "code sent" banner sitting next to it. In tests
        // (no JS) phoneTokenReceived() is called directly and this
        // handshake is bypassed.
        $this->dispatch('firebase-send-phone-otp', phone: PhoneNumber::e164($this->phone));
        $this->status = 'Sending a verification code to '.PhoneNumber::e164($this->phone).'…';
    }

    /** customer-auth.js confirms signInWithPhoneNumber actually sent the SMS. */
    #[On('firebase-phone-otp-sent')]
    public function phoneOtpSent(): void
    {
        if (blank($this->verifiedPhoneE164)) {
            $this->step = 'verify_phone';
            $this->error = '';
            $this->status = 'Enter the code we sent to '.PhoneNumber::e164($this->phone).'.';
        }
    }

    #[On('firebase-error')]
    public function firebaseError(string $message): void
    {
        $this->error = $message;
        // Clear any optimistic "sending…" / "code sent" line so the user
        // sees a single failure, not a green confirmation beside a red error.
        $this->status = '';
    }

    /** Invoked with the Firebase ID token once the SMS code is confirmed client-side. */
    #[On('firebase-phone-token')]
    public function phoneTokenReceived(string $idToken, FirebaseTokenVerifier $firebase): void
    {
        $this->reset('error');

        try {
            $identity = $firebase->verify($idToken);
        } catch (FirebaseAuthException $e) {
            report($e);
            $this->error = 'Could not verify that code. Request a new one.';

            return;
        }

        if (! $identity->isPhoneProvider() || blank($identity->phoneNumber)) {
            $this->error = 'That verification did not include a mobile number.';

            return;
        }

        // Fail fast: a number that already belongs to an account cannot be
        // registered again (RegisterProviderAction rejects it at submit
        // with AccountAlreadyExistsException). Catch it here, the moment
        // the number is proven, so the applicant never fills in a detail or
        // uploads a document against a number they can't use. Same
        // `phone` shape the Action checks — national digits.
        if (User::where('phone', PhoneNumber::national($identity->phoneNumber))->exists()) {
            $this->error = 'An account with this mobile number already exists. Please sign in instead.';
            $this->step = 'phone';

            return;
        }

        $this->verifiedPhoneE164 = $identity->phoneNumber;
        $this->phoneFirebaseUid = $identity->uid;
        $this->step = 'details';
        $this->status = 'Mobile number verified. Fill in your details to finish.';
    }

    public function changePhone(): void
    {
        $this->reset('error', 'status', 'verifiedPhoneE164', 'phoneFirebaseUid');
        $this->step = 'phone';
    }

    // ─────────────────────── Address / coverage ──────────────────────────

    /**
     * Wired to the shared [data-locate-address] button through
     * cfWireLocateButton (resources/js/geolocation.js) — the same contract
     * the customer add-address form uses. The franchise / zone is resolved
     * from the pin itself, never accepted from the client; an
     * out-of-coverage pin is recorded, not rejected (D3).
     *
     * A geolocation failure never reaches this method: the browser's error
     * callback restores the button and reveals a visible "type your address
     * instead" message (the same pattern as the customer location picker),
     * and submitApplication() then accepts the typed address on its own.
     */
    public function useCurrentLocationForNewAddress(float $lat, float $lng): void
    {
        $this->reset('error');

        validator(
            ['lat' => $lat, 'lng' => $lng],
            ['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']],
        )->validate();

        $this->lat = $lat;
        $this->lng = $lng;
        $this->resolveZoneFromPin();
    }

    private function resolveZoneFromPin(): void
    {
        $zone = app(CustomerLocationContext::class)->nearestCoveringZone((float) $this->lat, (float) $this->lng);

        if ($zone) {
            $this->resolvedZoneId = $zone->id;
            $this->resolvedZoneName = $zone->name;
            $this->outOfCoverage = false;

            return;
        }

        // Out of coverage: keep the applicant moving (D3). Attach to the
        // nearest zone by raw distance purely so the row has a franchise to
        // land in (providers.franchise_id is NOT NULL) and flag it so the
        // reviewing operator confirms / relocates the placement — the
        // registration_lat/lng stored on submit give them the exact spot.
        $nearest = $this->nearestZoneIgnoringRadius((float) $this->lat, (float) $this->lng);
        $this->resolvedZoneId = $nearest?->id;
        $this->resolvedZoneName = $nearest?->name;
        $this->outOfCoverage = true;
    }

    private function nearestZoneIgnoringRadius(float $lat, float $lng): ?Zone
    {
        $dispatch = app(DispatchService::class);

        return Zone::query()
            ->where('is_active', true)
            ->whereNotNull('center_lat')->whereNotNull('center_lng')
            ->get()
            ->sortBy(fn (Zone $z) => $dispatch->haversineKm($lat, $lng, (float) $z->center_lat, (float) $z->center_lng))
            ->first();
    }

    /**
     * D3 fallback for when no pin resolved to a zone at all (the geolocation
     * button failed, was declined, or was never tapped). The application
     * still has to land somewhere — providers.franchise_id is NOT NULL — so
     * attach it to any active zone that has a franchise, purely as a holding
     * place. outOfCoverage is set and registration_address is stored, so the
     * reviewing operator relocates it. Null only if the platform has no
     * active, franchised zone configured at all.
     */
    private function anyActiveZoneForManualPlacement(): ?Zone
    {
        return Zone::query()
            ->where('is_active', true)
            ->whereNotNull('franchise_id')
            ->orderBy('id')
            ->first();
    }

    // ─────────────────────────── Finish ──────────────────────────────────

    public function submitApplication(RegisterProviderAction $register, KycDocumentService $kyc, CreateParcelRiderAction $riders): void
    {
        $this->reset('error', 'status');

        // One-page form: the details/address/KYC fields are filled while the
        // OTP is still in flight, so submit is gated on the *proven number*
        // alone, not on a view step. verifiedPhoneE164 is #[Locked] and only
        // ever set by phoneTokenReceived() after a server-side token verify.
        if (blank($this->verifiedPhoneE164)) {
            $this->error = 'Verify your mobile number first.';

            return;
        }

        if ($this->isThrottled('provider-reg-submit', $this->verifiedPhoneE164, maxPerIdentifier: SiteControls::submitPerMobile(), maxPerIp: 20)) {
            return;
        }

        $requirements = $kyc->requirementsFor($this->documentsFor(), $this->resolvedCountryId());
        $requiredTypes = $requirements->where('is_required', true)->pluck('document_type');

        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['required', 'string', 'min:6', 'max:500'],
            'terms' => ['accepted'],
            'documents' => ['array'],
            'documents.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'skills' => array_values(array_filter([
                'nullable',
                'array',
                SiteControls::servicesMax() > 0 ? 'max:'.SiteControls::servicesMax() : null,
            ])),
            'skills.*' => ['integer'],
        ], [
            'skills.max' => 'Choose up to '.SiteControls::servicesMax().' services.',
            'terms.accepted' => 'Please accept the partner terms to continue.',
            'password.confirmed' => 'The two passwords do not match.',
            'address.required' => 'Enter your work address.',
        ]);

        // Only real, active, customer-visible categories count — the array is client-editable state.
        // "Required" never blocks when no category is published yet — there would be nothing to tick.
        // A delivery rider offers no service categories: the step does not apply.
        $offerable = (SiteControls::servicesStep() === 'hidden' || $this->isRider()) ? collect() : $this->offerableCategories();
        $skillIds = $offerable->whereIn('id', array_map('intval', $this->skills))->pluck('id')->values()->all();
        if (SiteControls::servicesStep() === 'required' && $offerable->isNotEmpty() && $skillIds === []) {
            $this->addError('skills', 'Choose at least one service you offer.');
        }

        foreach ($requiredTypes as $type) {
            if (! isset($this->documents[$type])) {
                $this->addError('documents.'.$type, 'This document is required.');
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        // Location routing. Decision D3: a coverage problem NEVER blocks the
        // application. A resolved pin (covering, or nearest-by-distance)
        // routes it directly; anything less — the geolocation button failed,
        // was declined, or was never tapped — still goes through on the
        // typed address alone, attached to a holding zone and flagged
        // out-of-coverage for an operator to place by hand. The one hard
        // stop left is "there is no active service area at all to attach
        // to", and even then we name which piece is missing rather than
        // return one catch-all line.
        $zone = $this->resolvedZoneId
            ? Zone::where('is_active', true)->find($this->resolvedZoneId)
            : null;

        if (! $zone) {
            $zone = $this->anyActiveZoneForManualPlacement();

            if (! $zone) {
                $missing = [];
                if (blank($this->address)) {
                    $missing[] = 'a work address';
                }
                if ($this->lat === null || $this->lng === null) {
                    $missing[] = 'a location pin';
                }
                if ($this->resolvedZoneId === null) {
                    $missing[] = 'a matching service area';
                }

                $this->error = 'We can’t route your application yet — it needs '
                    .Arr::join($missing, ', ', ' and ')
                    .'. Try “Use my current location” again, or come back shortly.';

                return;
            }

            // Typed address, no usable pin: accepted, but flagged so an
            // operator confirms the placement (D3).
            $this->outOfCoverage = true;
        }

        $this->hitThrottle('provider-reg-submit', $this->verifiedPhoneE164, SiteControls::windowSeconds());

        $providerId = null;
        try {
            if ($this->isRider()) {
                DB::transaction(function () use ($riders, $kyc, $zone, $requirements) {
                    $rider = $riders->registerApplicant(
                        zone: $zone,
                        name: $this->name,
                        nationalPhone: PhoneNumber::national($this->verifiedPhoneE164),
                        email: filled($this->email) ? $this->email : null,
                        plainPassword: $this->password,
                        registration: ['address' => $this->address, 'lat' => $this->lat, 'lng' => $this->lng, 'out_of_coverage' => $this->outOfCoverage],
                    );

                    foreach ($requirements as $req) {
                        $file = $this->documents[$req->document_type] ?? null;
                        if ($file) {
                            $kyc->upload($rider, $req->document_type, $file, $rider->user, 'self');
                        }
                    }
                });
            } else {
                DB::transaction(function () use ($register, $kyc, $zone, $requirements, $skillIds, &$providerId) {
                    $provider = $register->execute(
                        name: $this->name,
                        phone: PhoneNumber::national($this->verifiedPhoneE164),
                        franchiseId: $zone->franchise_id,
                        zoneId: $zone->id,
                        plainPassword: $this->password,
                        email: filled($this->email) ? $this->email : null,
                        phoneVerified: true,
                        registration: [
                            'address' => $this->address,
                            'lat' => $this->lat,
                            'lng' => $this->lng,
                        ],
                        skills: $skillIds,
                    );

                    $providerId = $provider->id;

                    foreach ($requirements as $req) {
                        $file = $this->documents[$req->document_type] ?? null;
                        if ($file) {
                            $kyc->upload($provider, $req->document_type, $file, $provider->user, 'self');
                        }
                    }
                });
            }
        } catch (AccountAlreadyExistsException $e) {
            $this->error = 'An account with this mobile number already exists. Please sign in instead.';

            return;
        }

        \App\Models\PartnerLead::markConverted($this->leadId, PhoneNumber::national($this->verifiedPhoneE164), $providerId, $this->partnerRole);

        $this->clearThrottle('provider-reg-phone', $this->phone);
        $this->clearThrottle('provider-reg-submit', $this->verifiedPhoneE164);

        // No Auth::login() — the applicant cannot sign in until an admin
        // approves; the dashboard would only show "pending KYC, no work".
        $this->submitted = true;
        $this->reset('password', 'password_confirmation', 'documents');
    }

    /** The categories an applicant can offer: the same active service categories customers browse. */
    private function offerableCategories(): \Illuminate\Support\Collection
    {
        return app(ServiceCatalogQuery::class)->categories()->get(['id', 'name', 'slug', 'image', 'color', 'module']);
    }

    private function resolvedCountryId(): ?int
    {
        if (! $this->resolvedZoneId) {
            return null;
        }

        return Zone::with('franchise')->find($this->resolvedZoneId)?->franchise?->country_id;
    }

    public function render()
    {
        $requirements = app(KycDocumentService::class)->requirementsFor($this->documentsFor(), $this->resolvedCountryId());

        $servicesStep = $this->isRider() ? 'hidden' : SiteControls::servicesStep();

        return view('livewire.provider.auth.register', [
            'requirements' => $requirements,
            'isRider' => $this->isRider(),
            'servicesStep' => $servicesStep,
            'servicesMax' => SiteControls::servicesMax(),
            'serviceCategories' => $servicesStep === 'hidden' ? collect() : $this->offerableCategories(),
        ])
            ->layout('components.layouts.provider', ['title' => $this->isRider() ? 'Become a delivery rider' : 'Become a partner']);
    }
}
