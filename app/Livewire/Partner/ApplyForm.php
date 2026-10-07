<?php

namespace App\Livewire\Partner;

use App\Livewire\Customer\Auth\Concerns\InteractsWithAuthThrottle;
use App\Models\City;
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

    /** Slug of a listed city, or OTHER_CITY when the person's city is not in the list. */
    public string $cityChoice = '';

    /** The typed city; used only when OTHER_CITY is chosen. */
    public string $city = '';

    public const OTHER_CITY = '__other';

    public bool $consent = false;

    /** Honeypot: real people never see or fill this. */
    public string $website = '';

    public string $error = '';

    /** '' (form) | 'handoff' | 'waitlist' | 'received' (honeypot) — set only by the server. */
    #[Locked]
    public string $outcome = '';

    public function mount(): void
    {
        // No listed cities (or no cities.slug column yet): the free-text city is the only way in.
        if (self::listedCities()->isEmpty()) {
            $this->cityChoice = self::OTHER_CITY;
        }

        $codes = array_column(PartnerPageData::roles(), 'code');
        $this->role = in_array('service', $codes, true) ? 'service' : ($codes[0] ?? '');
    }

    /** "Add another application": back to a clean form (the role stays as the person left it). */
    public function again(): void
    {
        $this->reset(['name', 'phone', 'cityChoice', 'city', 'consent', 'website', 'error', 'outcome']);
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
            'cityChoice' => ['required', 'string', 'max:120'],
            'city' => [Rule::requiredIf($this->cityChoice === self::OTHER_CITY), 'nullable', 'string', 'min:2', 'max:120', "regex:/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\-]*$/u"],
            'consent' => ['accepted'],
        ], [
            'cityChoice.required' => 'Choose your city.',
            'role.required' => 'Choose a role.', 'role.in' => 'Choose a role.',
            'name.required' => 'Enter your full name.', 'phone.required' => 'Enter your mobile number.', 'city.required' => 'Enter your city.',
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

        // The city comes from the server's list, never from the browser's text: a listed choice is looked up by slug
        // and its stored name and slug are used; the free-text box counts only for "My city is not listed".
        $citySlug = null;
        if ($this->cityChoice === self::OTHER_CITY) {
            $cityName = trim($this->city);
        } else {
            $listed = self::listedCities()->firstWhere('slug', $this->cityChoice);
            if ($listed === null) {
                $this->addError('cityChoice', 'Choose your city.');

                return;
            }
            $cityName = $listed->name;
            $citySlug = $listed->slug;
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
                'city' => $cityName,
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
                // The slug of a listed city only; nothing for a typed city (the sign-up then starts with an empty address).
                'city' => $citySlug,
            ]);
            $this->outcome = 'handoff';

            return;
        }

        $this->outcome = 'waitlist';
    }

    /**
     * Whether cities.slug exists (it arrives with an earlier migration). Checked once per request; when it is missing
     * the page must still render, with the free-text city only and no query on the column.
     */
    public static function citySlugColumnExists(): bool
    {
        $attributes = request()->attributes;
        if (! $attributes->has('partner_cities_slug')) {
            try {
                $attributes->set('partner_cities_slug', \Illuminate\Support\Facades\Schema::hasColumn('cities', 'slug'));
            } catch (\Throwable) {
                $attributes->set('partner_cities_slug', false);
            }
        }

        return (bool) $attributes->get('partner_cities_slug');
    }

    /** Active cities that have a slug (QA/demo cities excluded), for the select. */
    public static function listedCities(): \Illuminate\Support\Collection
    {
        if (! self::citySlugColumnExists()) {
            return collect();
        }

        try {
            return City::query()->where('is_active', true)->whereNotNull('slug')->notQa()->orderBy('name')->get(['id', 'name', 'slug']);
        } catch (\Throwable) {
            return collect();
        }
    }

    public function render()
    {
        return view('livewire.partner.apply-form', [
            'cities' => self::listedCities(),
            'roles' => PartnerPageData::roles(),
            'consentText' => P::text('form.consent_text'),
            'waitlistNote' => P::text('form.waitlist_note'),
            'doneTitle' => P::text('form.done_title'),
            'doneBody' => P::text('form.done_body'),
        ]);
    }
}
