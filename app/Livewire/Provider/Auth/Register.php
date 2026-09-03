<?php

namespace App\Livewire\Provider\Auth;

use App\Actions\RegisterProviderAction;
use App\Contracts\FirebaseTokenVerifier;
use App\Exceptions\AccountAlreadyExistsException;
use App\Exceptions\FirebaseAuthException;
use App\Livewire\Customer\Auth\Concerns\InteractsWithAuthThrottle;
use App\Models\User;
use App\Models\Zone;
use App\Services\Customer\CustomerLocationContext;
use App\Services\DispatchService;
use App\Services\Kyc\KycDocumentService;
use App\Support\PhoneNumber;
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
 * (D3); the optional email is stored unverified (D4); no skills picker
 * (D2); no applicant notification (D8); no existing-customer -> provider
 * attach (D10) — a taken phone is refused with "sign in instead".
 */
class Register extends Component
{
    use InteractsWithAuthThrottle;
    use WithFileUploads;

    /** phone | verify_phone | details */
    #[Locked]
    public string $step = 'phone';

    public string $phone = '';

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $email = '';

    public string $address = '';

    public bool $terms = false;

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

    public function mount(): void
    {
        if (auth()->guard('web')->check()) {
            $this->redirectRoute(
                auth()->user()->providerProfile()->exists() ? 'provider.dashboard' : 'customer.home',
                navigate: true,
            );
        }
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

        if ($this->isThrottled('provider-reg-phone', $this->phone, maxPerIdentifier: 5)) {
            return;
        }
        $this->hitThrottle('provider-reg-phone', $this->phone);

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

    // ─────────────────────────── Finish ──────────────────────────────────

    public function submitApplication(RegisterProviderAction $register, KycDocumentService $kyc): void
    {
        $this->reset('error', 'status');

        if ($this->step !== 'details' || blank($this->verifiedPhoneE164)) {
            $this->error = 'Verify your mobile number first.';

            return;
        }

        if ($this->isThrottled('provider-reg-submit', $this->verifiedPhoneE164, maxPerIdentifier: 3, maxPerIp: 10)) {
            return;
        }

        $requirements = $kyc->requirementsFor('provider', $this->resolvedCountryId());
        $requiredTypes = $requirements->where('is_required', true)->pluck('document_type');

        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['required', 'string', 'min:6', 'max:500'],
            'terms' => ['accepted'],
            'documents' => ['array'],
            'documents.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ], [
            'terms.accepted' => 'Please accept the partner terms to continue.',
            'password.confirmed' => 'The two passwords do not match.',
            'address.required' => 'Enter your work address.',
        ]);

        foreach ($requiredTypes as $type) {
            if (! isset($this->documents[$type])) {
                $this->addError('documents.'.$type, 'This document is required.');
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        if ($this->lat === null || $this->lng === null || $this->resolvedZoneId === null) {
            $this->error = 'Add your work location with the “Use my current location” button so we can route your application.';

            return;
        }

        $zone = Zone::where('is_active', true)->find($this->resolvedZoneId);
        if (! $zone) {
            $this->error = 'We could not confirm your service area. Please try again.';

            return;
        }

        $this->hitThrottle('provider-reg-submit', $this->verifiedPhoneE164);

        try {
            DB::transaction(function () use ($register, $kyc, $zone, $requirements) {
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
                );

                foreach ($requirements as $req) {
                    $file = $this->documents[$req->document_type] ?? null;
                    if ($file) {
                        $kyc->upload($provider, $req->document_type, $file, $provider->user, 'self');
                    }
                }
            });
        } catch (AccountAlreadyExistsException $e) {
            $this->error = 'An account with this mobile number already exists. Please sign in instead.';

            return;
        }

        $this->clearThrottle('provider-reg-phone', $this->phone);
        $this->clearThrottle('provider-reg-submit', $this->verifiedPhoneE164);

        // No Auth::login() — the applicant cannot sign in until an admin
        // approves; the dashboard would only show "pending KYC, no work".
        $this->submitted = true;
        $this->reset('password', 'password_confirmation', 'documents');
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
        $requirements = app(KycDocumentService::class)->requirementsFor('provider', $this->resolvedCountryId());

        return view('livewire.provider.auth.register', ['requirements' => $requirements])
            ->layout('components.layouts.provider', ['title' => 'Become a partner']);
    }
}
