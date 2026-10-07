<?php

namespace App\Livewire\Partner;

use App\Livewire\Customer\Auth\Concerns\InteractsWithAuthThrottle;
use App\Models\PartnerLead;
use App\Support\Acquisition\AcquisitionContext;
use App\Support\PartnerPage\PartnerPageData;
use App\Support\PartnerPage\PartnerPageSettings as P;
use App\Support\PhoneNumber;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The application form on /partners (REF 1CF-PARTNER-PAGE-001). Collects role, name, mobile, city and consent, saves
 * a lead, and hands the service role off to the EXISTING provider sign-up (/provider/register) — OTP and KYC stay
 * there; this is not a second sign-up. Roles whose module is not live are saved as waiting-list entries and end on a
 * confirmation screen with no "Continue in the app" button.
 *
 * Rules: server-side validation with the existing phone rule; throttled per mobile and per IP; a honeypot; the lead
 * status is set here from the role's registry state and is never read from the browser; the reply is identical
 * whether or not the number already has an account (no account lookup happens here at all); errors are generic.
 * Nothing personal ever goes in a URL: the hand-off passes only a lead id and safe slugs through the session.
 */
class ApplyForm extends Component
{
    use InteractsWithAuthThrottle;

    public string $role = '';

    public string $name = '';

    public string $phone = '';

    public string $city = '';

    public bool $consent = false;

    /** Honeypot: real people never see or fill this. */
    public string $website = '';

    public string $error = '';

    /** '' (form) | 'handoff' | 'waitlist' | 'received' (honeypot) — set only by the server. */
    #[Locked]
    public string $outcome = '';

    public function mount(): void
    {
        $codes = array_column(PartnerPageData::roles(), 'code');
        $this->role = in_array('service', $codes, true) ? 'service' : ($codes[0] ?? '');
    }

    /** "Add another application": back to a clean form (the role stays as the person left it). */
    public function again(): void
    {
        $this->reset(['name', 'phone', 'city', 'consent', 'website', 'error', 'outcome']);
        $this->resetErrorBag();
    }

    public function submit(): void
    {
        $this->error = '';

        $roles = collect(PartnerPageData::roles())->keyBy('code');

        $this->validate([
            'role' => ['required', Rule::in($roles->keys()->all())],
            'name' => ['required', 'string', 'min:2', 'max:120', "regex:/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\-]*$/u"],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+][0-9 \\-]*$/'],
            'city' => ['required', 'string', 'min:2', 'max:120', "regex:/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\-]*$/u"],
            'consent' => ['accepted'],
        ], [
            'role.required' => 'Choose a role.', 'role.in' => 'Choose a role.',
            'name.regex' => 'Enter your name.', 'city.regex' => 'Enter your city.',
            'phone.regex' => 'Enter a valid mobile number.',
            'consent.accepted' => 'Please tick the box to continue.',
        ]);

        if (! PhoneNumber::looksValid($this->phone)) {
            $this->addError('phone', 'Enter a valid 10-digit mobile number.');

            return;
        }

        // A bot that fills the hidden field gets the same friendly screen and nothing is saved.
        if ($this->website !== '') {
            $this->outcome = 'received';

            return;
        }

        $national = PhoneNumber::national($this->phone);

        if ($this->isThrottled('partner-lead', $national, maxPerIdentifier: 3, maxPerIp: 10)) {
            return;
        }
        $this->hitThrottle('partner-lead', $national, 600);

        $card = $roles[$this->role];

        try {
            $lead = PartnerLead::capture([
                'name' => trim($this->name),
                'phone' => $national,
                'city' => trim($this->city),
                'role' => $this->role,
                // Set here from the registry; never from the browser.
                'status' => $card['hands_off'] ? PartnerLead::STATUS_HANDED_OFF : PartnerLead::STATUS_WAITLIST,
                'consent_text' => P::text('form.consent_text'),
                'acquisition' => AcquisitionContext::current(),
                'source' => 'partner_page',
            ]);
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'Something went wrong. Please try again.';

            return;
        }

        if ($card['hands_off']) {
            // Safe values only — a lead id and slugs. The phone is never carried across; the sign-up verifies its own.
            session()->put('partner_lead', [
                'id' => $lead->id,
                'role' => $this->role,
                'city' => Str::slug($this->city),
            ]);
            $this->outcome = 'handoff';

            return;
        }

        $this->outcome = 'waitlist';
    }

    public function render()
    {
        return view('livewire.partner.apply-form', [
            'roles' => PartnerPageData::roles(),
            'consentText' => P::text('form.consent_text'),
            'doneTitle' => P::text('form.done_title'),
            'doneBody' => P::text('form.done_body'),
        ]);
    }
}
