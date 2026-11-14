<?php

namespace App\Livewire\Venue;

use App\Livewire\Venue\Concerns\InteractsWithHalls;
use App\Models\HouseListing;
use App\Models\Zone;
use App\Services\ActivityLogger;
use App\Support\HomeSettings;
use App\Support\ImageShrinker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Rental portal → one home for rent (owner 2026-11-14). Six numbered, coloured steps; almost everything is a tap
 * (kind, size, floor, furnishing, who can rent, area, facilities, rules, deposit months) from the Rental Settings
 * lists for the home's own area, so a hill town or a coastal city shows its own choices. The owner types only what
 * only they know: the rent, the size, a landmark, the address and a few lines about the home.
 *
 * Draft / Needs changes: everything changes at once, then "Send for review". Waiting for review: locked. Approved:
 * rent, deposit, dates, facilities and rules change at once; photos, description, landmark and address wait for the
 * team while the listing stays up with the old ones. The title ("2 BHK flat for rent in Magunta Layout") is made from
 * the taps, never typed.
 */
class HomeEditor extends Component
{
    use InteractsWithHalls;
    use WithFileUploads;

    #[Locked]
    public ?int $listingId = null;

    public string $homeType = '';
    public string $size = '';
    public string $area = '';
    public string $floor = '';
    public string $facing = '';
    public string $furnishing = '';
    /** @var list<string> */
    public array $tenants = [];
    public string $rent = '';
    public string $deposit = '';
    public string $maintenance = '';
    public string $availableFrom = '';
    public string $zoneId = '';
    public string $landmark = '';
    public string $addressLine = '';
    public string $lat = '';
    public string $lng = '';
    /** @var list<string> */
    public array $amenities = [];
    /** @var list<string> */
    public array $ruleList = [];
    public string $about = '';

    public $cover = null;
    /** @var array<int, mixed> */
    public array $newPhotos = [];
    /** @var list<string> */
    public array $photos = [];

    public string $notice = '';

    public function mount(?int $listingId = null): void
    {
        $partner = $this->partner();
        if ($listingId !== null) {
            $home = $this->ownHome($listingId);
            $v = fn (string $field) => array_key_exists($field, (array) $home->pending_changes) ? $home->pending_changes[$field] : $home->{$field};
            $this->listingId = $home->id;
            $this->homeType = (string) $home->home_type;
            $this->size = (string) $home->size;
            $this->area = $home->area ? (string) $home->area : '';
            $this->floor = (string) $home->floor;
            $this->facing = (string) $home->facing;
            $this->furnishing = (string) $home->furnishing;
            $this->tenants = array_values((array) ($home->tenants ?? []));
            $this->rent = $home->rent !== null ? (string) (float) $home->rent : '';
            $this->deposit = $home->deposit !== null ? (string) (float) $home->deposit : '';
            $this->maintenance = $home->maintenance !== null ? (string) (float) $home->maintenance : '';
            $this->availableFrom = (string) $home->available_from?->toDateString();
            $this->zoneId = $home->zone_id ? (string) $home->zone_id : '';
            $this->landmark = (string) $v('landmark');
            $this->addressLine = (string) $v('address_line');
            $this->lat = $v('lat') !== null ? (string) (float) $v('lat') : '';
            $this->lng = $v('lng') !== null ? (string) (float) $v('lng') : '';
            $this->amenities = array_values((array) ($home->amenities ?? []));
            $this->ruleList = array_values((array) ($home->rule_list ?? []));
            $this->about = (string) $v('about');
            $this->photos = array_values((array) ($v('photos') ?? []));
        } else {
            $this->zoneId = $partner->zone_id ? (string) $partner->zone_id : '';
        }
        if ($this->availableFrom === '') {
            $this->availableFrom = now()->toDateString();
        }
    }

    private function home(): ?HouseListing
    {
        return $this->listingId ? $this->ownHome($this->listingId) : null;
    }

    protected function ownHome(int $id): HouseListing
    {
        return HouseListing::with('franchise')->where('provider_id', $this->partner()->id)->find($id) ?? abort(404);
    }

    /** The franchise this home belongs to (its own, or the owner's for a new one). */
    private function franchiseId(): ?int
    {
        return $this->home()?->franchise_id ?? $this->partner()->franchise_id;
    }

    /** Settings scope for the lists: the area picked now, its franchise, city and country. */
    private function scope(): array
    {
        $franchise = \App\Models\Franchise::find($this->franchiseId());

        return array_filter([
            'zone_id' => $this->zoneId !== '' ? (int) $this->zoneId : null, 'franchise_id' => $franchise?->id,
            'city_id' => $franchise?->city_id, 'country_id' => $franchise?->country_id,
        ]);
    }

    /** A list from Rental Settings for this area, plus what this home already has (a value taken off later stays valid). */
    private function choices(string $key, array $current = []): array
    {
        return array_values(array_unique(array_merge(HomeSettings::listIn($key, $this->scope()), array_filter($current))));
    }

    /** Deposit button: months × rent. */
    public function depositMonths(int $months): void
    {
        if (is_numeric($this->rent) && (float) $this->rent > 0) {
            $this->deposit = (string) round((float) $this->rent * max(1, $months));
        }
    }

    public function readyNow(): void
    {
        $this->availableFrom = now()->toDateString();
    }

    public function removePhoto(int $index): void
    {
        unset($this->photos[$index]);
        $this->photos = array_values($this->photos);
    }

    private function homeRules(): array
    {
        $scope = $this->scope();
        $home = $this->home();

        $business = HomeSettings::isCommercial($this->homeType, $scope);

        return [
            'homeType' => ['required', Rule::in(array_merge($this->choices('homes.types', [(string) $home?->home_type]), HomeSettings::listIn('homes.commercial_types', $scope)))],
            // A business kind (shop, office...) has no BHK size.
            'size' => [$business ? 'nullable' : 'required', Rule::in($this->choices('homes.sizes', [(string) $home?->size]))],
            'area' => ['nullable', 'integer', 'min:10', 'max:1000000'],
            'floor' => ['nullable', Rule::in($this->choices('homes.floors', [(string) $home?->floor]))],
            'facing' => ['nullable', Rule::in($this->choices('homes.facing', [(string) $home?->facing]))],
            'furnishing' => ['required', Rule::in($this->choices('homes.furnishing', [(string) $home?->furnishing]))],
            'tenants' => ['required', 'array', 'min:1'],
            'tenants.*' => [Rule::in($this->choices($business ? 'homes.business_tenants' : 'homes.tenants', (array) ($home?->tenants ?? [])))],
            'rent' => ['required', 'numeric', 'min:'.(int) HomeSettings::get('homes.min_rent', $scope), 'max:'.(int) HomeSettings::get('homes.max_rent', $scope)],
            'deposit' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'maintenance' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'availableFrom' => ['required', 'date', 'after_or_equal:'.now()->subYear()->toDateString(), 'before_or_equal:'.now()->addYear()->toDateString()],
            'zoneId' => ['required', Rule::in(Zone::where('franchise_id', $this->franchiseId())->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'landmark' => ['nullable', 'string', 'max:160'],
            'addressLine' => ['required', 'string', 'min:6', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'amenities' => ['array'],
            'amenities.*' => [Rule::in(array_merge(HomeSettings::amenityList($scope), (array) ($home?->amenities ?? [])))],
            'ruleList' => ['array'],
            'ruleList.*' => [Rule::in($this->choices('homes.rules', (array) ($home?->rule_list ?? [])))],
            'about' => ['nullable', 'string', 'max:3000'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'newPhotos' => ['array', 'max:'.HouseListing::MAX_PHOTOS],
            'newPhotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];
    }

    private function homeMessages(): array
    {
        return [
            'homeType.required' => 'Tap the kind of home.',
            'size.required' => 'Tap the size of the home.',
            'furnishing.required' => 'Tap how furnished it is.',
            'tenants.required' => 'Tap who you rent to.',
            'tenants.min' => 'Tap who you rent to.',
            'rent.required' => 'Enter the rent per month.',
            'rent.min' => 'The rent looks too low. Check the amount.',
            'rent.max' => 'The rent looks too high. Check the amount.',
            'zoneId.required' => 'Tap the area the home is in.',
            'zoneId.in' => 'Tap the area the home is in.',
            'addressLine.required' => 'Enter the address. Customers see it only after you accept their visit.',
            'newPhotos.max' => 'Up to '.HouseListing::MAX_PHOTOS.' photos.',
        ];
    }

    public function save(): ?HouseListing
    {
        $this->reset('notice');
        $existing = $this->home();
        if ($existing?->status === 'in_review') {
            $this->addError('home', 'We are checking this property. You can change it again once we reply.');

            return null;
        }
        $this->validate($this->homeRules(), $this->homeMessages());
        if (count($this->photos) + count($this->newPhotos) > HouseListing::MAX_PHOTOS) {
            $this->addError('newPhotos', 'Up to '.HouseListing::MAX_PHOTOS.' photos. Remove some first.');

            return null;
        }

        $partner = $this->partner();
        $folder = 'homes/'.($existing?->id ?? 'new-'.$partner->id);
        $cover = $this->cover ? ImageShrinker::store($this->cover, $folder, 'hall_photo') : null;
        $photos = $this->photos;
        foreach ($this->newPhotos as $file) {
            $photos[] = ImageShrinker::store($file, $folder, 'hall_photo');
        }
        $zone = Zone::find((int) $this->zoneId);

        $reviewed = [
            'about' => trim($this->about) ?: null,
            'landmark' => trim($this->landmark) ?: null,
            'address_line' => trim($this->addressLine),
            'lat' => $this->lat !== '' ? round((float) $this->lat, 7) : null,
            'lng' => $this->lng !== '' ? round((float) $this->lng, 7) : null,
            'photos' => array_values($photos),
        ] + ($cover ? ['cover_image' => $cover] : []);
        $size = HomeSettings::isCommercial($this->homeType, $this->scope()) ? null : ($this->size ?: null);
        $now = [
            'title' => HouseListing::makeTitle($size, $this->homeType, $zone?->name),
            'home_type' => $this->homeType,
            'size' => $size,
            'area' => $this->area !== '' ? (int) $this->area : null,
            'floor' => $this->floor ?: null,
            'facing' => $this->facing ?: null,
            'furnishing' => $this->furnishing,
            'tenants' => array_values(array_unique($this->tenants)),
            'rent' => round((float) $this->rent, 2),
            'deposit' => $this->deposit !== '' ? round((float) $this->deposit, 2) : null,
            'maintenance' => $this->maintenance !== '' && (float) $this->maintenance > 0 ? round((float) $this->maintenance, 2) : null,
            'available_from' => $this->availableFrom,
            'zone_id' => $zone?->id,
            'amenities' => array_values(array_unique($this->amenities)) ?: null,
            'rule_list' => array_values(array_unique($this->ruleList)) ?: null,
        ];

        $waiting = false;
        $home = DB::transaction(function () use ($existing, $partner, $reviewed, $now, &$waiting) {
            if ($existing === null) {
                return HouseListing::create($reviewed + $now + [
                    'provider_id' => $partner->id,
                    'franchise_id' => $this->franchiseId(),
                    'slug' => HouseListing::uniqueSlug($now['title']),
                    'contact_phone' => $partner->user?->phone,
                    'status' => 'draft',
                    'is_active' => false,
                ]);
            }
            if ($existing->status === 'approved') {
                // The public link stays the same on a live home (old links never break).
                $existing->update($now);
                $wait = [];
                foreach ($reviewed as $field => $value) {
                    if (! self::same($existing->getAttribute($field), $value)) {
                        $wait[$field] = $value;
                    }
                }
                if ($wait !== [] || $existing->pending_changes !== null) {
                    $existing->update(['pending_changes' => $wait ?: null, 'pending_requested_at' => $wait ? now() : null, 'pending_rejection' => null]);
                    $waiting = $wait !== [];
                }

                return $existing;
            }
            $fields = $reviewed + $now;
            if ($existing->title !== $fields['title']) {
                $fields['slug'] = HouseListing::uniqueSlug($fields['title'], $existing->id);
            }
            $existing->update($fields);

            return $existing;
        });

        $this->reset('cover', 'newPhotos');
        ActivityLogger::logModel(auth()->user(), $home, $existing ? 'Home for rent updated (Rental portal)' : 'Home for rent added (Rental portal)', $waiting ? ['waiting_for_review' => array_keys((array) $home->pending_changes)] : []);
        $this->listingId = $home->id;
        $this->mount($home->id);
        $this->notice = $waiting ? 'Saved. Rent and details changed now; new photos, description or address show once we check them.' : 'Saved.';

        return $home;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return array_values((array) ($a ?? [])) === array_values((array) ($b ?? []));
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.0000001;
        }

        return (string) ($a ?? '') === (string) ($b ?? '');
    }

    public function submit(): void
    {
        $home = $this->save();
        if ($home === null || ! $home->isEditable()) {
            return;
        }
        $problems = self::submitProblems($home->refresh());
        if ($problems !== []) {
            $this->addError('home', implode(' ', $problems));
            $this->notice = 'Saved as a draft.';

            return;
        }
        $home->update(['status' => 'in_review', 'submitted_at' => now(), 'rejection_reason' => null]);
        ActivityLogger::logModel(auth()->user(), $home, 'Home for rent sent for review (Rental portal)');
        $this->notice = 'Sent for review. We check it and tell you here, usually within a day.';
    }

    /** @return list<string> what stops this home from being sent for review / approved */
    public static function submitProblems(HouseListing $home): array
    {
        $problems = [];
        $business = HomeSettings::isCommercial($home->home_type, $home->scope());
        if (! filled($home->home_type) || (! $business && ! filled($home->size)) || ! filled($home->furnishing)) {
            $problems[] = $business ? 'Tap the kind of property and furnishing.' : 'Tap the kind of property, its size and furnishing.';
        }
        if (! $home->rent || (float) $home->rent <= 0) {
            $problems[] = 'Enter the rent per month.';
        }
        if (! filled($home->address_line) || ! $home->zone_id) {
            $problems[] = 'Tap the area and enter the address.';
        }
        $min = (int) HomeSettings::get('homes.min_photos', $home->scope());
        if ($home->cover_image === null) {
            $problems[] = 'Add a cover photo.';
        }
        if (count((array) $home->photos) < $min) {
            $problems[] = "Add at least {$min} more photos (rooms, kitchen, bathroom).";
        }

        return $problems;
    }

    /** Approved listings: rented (hidden), still available (shown again for another period), switch off / on. */
    public function markRented(): void
    {
        $home = $this->home();
        if ($home?->status !== 'approved') {
            return;
        }
        $home->update(['rented_at' => now()]);
        ActivityLogger::logModel(auth()->user(), $home, 'Home marked rented (Rental portal)');
        $this->notice = 'Marked as rented. Customers no longer see it. Tap "Available again" when it is free.';
    }

    public function stillAvailable(): void
    {
        $home = $this->home();
        if ($home?->status !== 'approved') {
            return;
        }
        $home->update(['rented_at' => null, 'is_active' => true, 'expires_at' => now()->addDays((int) HomeSettings::get('homes.listing_days', $home->scope()))]);
        ActivityLogger::logModel(auth()->user(), $home, 'Home shown again: still available (Rental portal)');
        $this->notice = 'Customers see your property again until '.$home->expires_at->format('j M Y').'.';
    }

    public function withdrawChanges(): void
    {
        $home = $this->home();
        if ($home === null || $home->pending_changes === null) {
            return;
        }
        $home->update(['pending_changes' => null, 'pending_requested_at' => null]);
        ActivityLogger::logModel(auth()->user(), $home, 'Waiting home change withdrawn (Rental portal)');
        $this->mount($home->id);
        $this->notice = 'Change withdrawn.';
    }

    public function render()
    {
        $home = $this->home();
        $scope = $this->scope();
        $rent = is_numeric($this->rent) ? (float) $this->rent : 0;

        return view('livewire.venue.home-editor', [
            'home' => $home,
            'editable' => $home === null || $home->status !== 'in_review',
            'types' => array_values(array_diff($this->choices('homes.types', [$this->homeType]), HomeSettings::listIn('homes.commercial_types', $scope))),
            'businessTypes' => HomeSettings::listIn('homes.commercial_types', $scope),
            'business' => HomeSettings::isCommercial($this->homeType, $scope),
            'sizes' => $this->choices('homes.sizes', [$this->size]),
            'floors' => $this->choices('homes.floors', [$this->floor]),
            'facings' => $this->choices('homes.facing', [$this->facing]),
            'furnishings' => $this->choices('homes.furnishing', [$this->furnishing]),
            'tenantChoices' => $this->choices(HomeSettings::isCommercial($this->homeType, $scope) ? 'homes.business_tenants' : 'homes.tenants', $this->tenants),
            'ruleChoices' => $this->choices('homes.rules', $this->ruleList),
            'amenityGroups' => HomeSettings::amenityGroups($scope),
            'depositMonths' => HomeSettings::numbersIn('homes.deposit_months', $scope),
            'areaUnit' => HomeSettings::areaUnit($scope),
            'zones' => Zone::where('franchise_id', $this->franchiseId())->orderBy('name')->get(['id', 'name']),
            'minPhotos' => (int) HomeSettings::get('homes.min_photos', $scope),
            'symbol' => \App\Models\Setting::get('locale.currency_symbol', '₹'),
            'rentValue' => $rent,
        ])->layout('components.layouts.venue', ['title' => $home ? $home->title : 'Add a home for rent']);
    }
}
