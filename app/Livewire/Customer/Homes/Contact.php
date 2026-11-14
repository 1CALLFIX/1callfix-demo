<?php

namespace App\Livewire\Customer\Homes;

use App\Models\HouseListing;
use App\Models\HouseVisit;
use App\Services\Homes\HouseListingService;
use App\Support\HomeSettings;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Property for rent — the home page's contact panel, loaded on its own request (the page itself is cached): the rent,
 * "Ask for a visit" (tap a day and a time from Rental Settings, who you are, a short message) and "Show owner's
 * number" when Rental Settings allows it. Signed out: one button to sign in and come back.
 */
#[Lazy]
class Contact extends Component
{
    #[Locked]
    public int $listingId = 0;

    public string $date = '';
    public string $slot = '';
    public string $tenantType = '';
    public string $name = '';
    public string $phone = '';
    public string $message = '';
    public bool $asking = false;
    public ?string $ownerPhone = null;
    public string $error = '';
    public string $done = '';

    public function mount(int $listingId): void
    {
        $this->listingId = $listingId;
        if ($user = auth()->user()) {
            $this->name = (string) $user->name;
            $this->phone = (string) $user->phone;
        }
    }

    private function listing(): HouseListing
    {
        $home = HouseListing::with(['franchise.city', 'provider.user'])->find($this->listingId);
        abort_unless($home && HouseListingService::visible($home), 404);

        return $home;
    }

    public function placeholder()
    {
        return '<div class="h-64 animate-pulse rounded-2xl bg-slate-100" aria-hidden="true"></div>';
    }

    public function startAsking(): void
    {
        $this->asking = true;
        $this->reset('error');
    }

    public function ask(HouseListingService $homes): void
    {
        $this->reset('error');
        abort_unless(auth()->check(), 403);
        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+][0-9 \-]*$/'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        $home = $this->listing(); // a 404 outside the try (it is a RuntimeException too)
        try {
            $homes->requestVisit(auth()->user(), $home, $this->date, $this->slot, $this->name, $this->phone, $this->tenantType ?: null, $this->message ?: null);
            $this->asking = false;
            $this->done = \App\Support\SiteText::get('homes.contact.asked');
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function showPhone(HouseListingService $homes): void
    {
        $this->reset('error');
        abort_unless(auth()->check(), 403);
        $home = $this->listing();
        try {
            $this->ownerPhone = $homes->revealPhone(auth()->user(), $home);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $home = $this->listing();
        $scope = $home->scope();
        [$first, $last] = HouseListingService::visitWindow($home);
        $days = [];
        for ($d = Carbon::parse($first); $d->lte(Carbon::parse($last)); $d->addDay()) {
            $days[$d->toDateString()] = $d->isToday() ? \App\Support\SiteText::get('homes.contact.today') : ($d->isTomorrow() ? \App\Support\SiteText::get('homes.contact.tomorrow') : $d->format('D j M'));
        }
        $user = auth()->user();
        $open = $user ? HouseVisit::where('customer_id', $user->id)->where('house_listing_id', $home->id)->whereIn('status', HouseVisit::OPEN)->first() : null;
        $symbol = \App\Models\Setting::get('locale.currency_symbol', '₹');

        return view('livewire.customer.homes.contact', [
            'home' => $home,
            'rent' => $symbol.number_format((float) $home->rent),
            'deposit' => $home->deposit ? $symbol.number_format((float) $home->deposit) : null,
            'days' => $days,
            'slots' => HomeSettings::listIn('homes.visit_slots', $scope),
            'tenantChoices' => (array) ($home->tenants ?: HomeSettings::listIn(HomeSettings::isCommercial($home->home_type, $scope) ? 'homes.business_tenants' : 'homes.tenants', $scope)),
            'phoneAllowed' => HomeSettings::on('homes.show_owner_phone', $scope),
            'openVisit' => $open,
            'own' => $user && $home->provider?->user_id === $user->id,
            'signInUrl' => route('customer.login', ['intended' => '/'.ltrim(parse_url(route('customer.homes.show', [(string) $home->franchise?->city?->slug, $home->slug]), PHP_URL_PATH), '/')]),
        ]);
    }
}
