<?php

namespace App\Livewire\Customer\Account;

use App\Models\RentalItem;
use App\Services\ActivityLogger;
use App\Services\RentalItems\RentalItemService;
use App\Support\ImageShrinker;
use App\Support\PostTypes;
use App\Support\RentalItemSettings;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Account → Rent out your things → post / change an item (Rent anything, owner decision 2026-11-18). Four numbered,
 * coloured steps: photos, what it is, price, where. The person types only the name, the price and (optionally) a few
 * words about it; everything else is a tap. One Send / Save bar at the bottom. The team approves before customers see it.
 * Second-hand (SecondHandEditor, 2026-11-19) is the same editor for used things people sell: one price with
 * "Price can be discussed", "I have the bill", no per-day price and no deposit.
 */
class RentItemEditor extends Component
{
    use WithFileUploads;

    /** Which kind of post this editor makes (PostTypes). */
    protected string $deal = PostTypes::RENT;

    #[Locked]
    public ?int $itemId = null;

    public string $name = '';
    public string $category = '';
    public string $condition = '';
    public string $price = '';
    public string $priceUnit = '';
    public string $deposit = '';
    public bool $canDeliver = false;
    public bool $negotiable = false;
    public bool $hasBill = false;
    public string $about = '';
    public string $zoneId = '';
    public string $landmark = '';
    /** @var list<string> */
    public array $photos = [];
    public $cover = null;
    public array $newPhotos = [];
    public string $notice = '';

    public function mount(?int $item = null): void
    {
        $this->itemId = $item;
        $existing = $this->item();
        if ($item !== null && $existing === null) {
            abort(404);
        }
        if ($existing) {
            $this->name = $existing->name;
            $this->category = (string) $existing->category;
            $this->condition = (string) $existing->condition;
            $this->price = $existing->price !== null ? rtrim(rtrim((string) $existing->price, '0'), '.') : '';
            $this->priceUnit = (string) $existing->price_unit;
            $this->deposit = $existing->deposit !== null ? rtrim(rtrim((string) $existing->deposit, '0'), '.') : '';
            $this->canDeliver = (bool) $existing->can_deliver;
            $this->negotiable = (bool) $existing->negotiable;
            $this->hasBill = (bool) $existing->has_bill;
            $this->about = (string) $existing->about;
            $this->zoneId = (string) $existing->zone_id;
            $this->landmark = (string) $existing->landmark;
            $this->photos = array_values((array) $existing->photos);

            return;
        }
        $zones = RentalItemService::postingZones($this->deal);
        if ($zones->count() === 1) {
            $this->zoneId = (string) $zones->first()->id;
        }
        if (! $this->sale()) {
            $this->priceUnit = $this->choices('items.price_units')[0] ?? '';
        }
    }

    private function item(): ?RentalItem
    {
        return $this->itemId ? RentalItem::where('user_id', auth()->id())->where('deal', $this->deal)->find($this->itemId) : null;
    }

    private function sale(): bool
    {
        return $this->deal === PostTypes::SELL;
    }

    /** The settings key for this kind of post (Second-hand has its own kinds, prices, ...). */
    private function key(string $name): string
    {
        return PostTypes::key($this->deal, $name);
    }

    private function scope(): array
    {
        $zone = $this->zoneId !== '' ? \App\Models\Zone::with('franchise')->find((int) $this->zoneId) : null;

        return array_filter(['zone_id' => $zone?->id, 'franchise_id' => $zone?->franchise_id, 'city_id' => $zone?->franchise?->city_id, 'country_id' => $zone?->franchise?->country_id]);
    }

    /** A list from Rental Settings for the picked area, plus what the item already has. @return list<string> */
    private function choices(string $key, array $current = []): array
    {
        return array_values(array_unique(array_merge(RentalItemSettings::listIn($key, $this->scope()), array_filter($current))));
    }

    public function pickZone(int $zoneId): void
    {
        $this->zoneId = (string) $zoneId;
    }

    public function removePhoto(int $index): void
    {
        unset($this->photos[$index]);
        $this->photos = array_values($this->photos);
    }

    private function rules(): array
    {
        $scope = $this->scope();
        $item = $this->item();

        return [
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'category' => ['required', Rule::in($this->choices($this->key('categories'), [(string) $item?->category]))],
            'condition' => ['nullable', Rule::in($this->choices($this->key('conditions'), [(string) $item?->condition]))],
            'price' => ['required', 'numeric', 'min:'.(int) RentalItemSettings::get($this->key('min_price'), $scope), 'max:'.(int) RentalItemSettings::get($this->key('max_price'), $scope)],
            'priceUnit' => $this->sale() ? ['nullable'] : ['required', Rule::in($this->choices('items.price_units', [(string) $item?->price_unit]))],
            'deposit' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'about' => ['nullable', 'string', 'max:2000'],
            'zoneId' => ['required', Rule::in(RentalItemService::postingZones($this->deal)->pluck('id')->push($item?->zone_id)->filter()->map(fn ($id) => (string) $id)->all())],
            'landmark' => ['nullable', 'string', 'max:160'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'newPhotos' => ['array', 'max:'.RentalItem::MAX_PHOTOS],
            'newPhotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];
    }

    private function messages(): array
    {
        return [
            'name.required' => $this->sale() ? 'Write what you are selling, e.g. "Godrej almirah" or "Samsung fridge".' : 'Write what you rent out, e.g. "Sony camera" or "JBL party speaker".',
            'name.min' => $this->sale() ? 'Write what you are selling, e.g. "Godrej almirah".' : 'Write what you rent out, e.g. "Sony camera".',
            'category.required' => 'Tap what kind of item it is.',
            'category.in' => 'Tap what kind of item it is.',
            'price.required' => 'Enter your price.',
            'price.min' => 'The price looks too low. Check the amount.',
            'price.max' => 'The price looks too high. Check the amount.',
            'priceUnit.required' => 'Tap per day, per hour or another.',
            'zoneId.required' => 'Tap your area.',
            'zoneId.in' => 'Tap your area.',
            'newPhotos.max' => 'Up to '.RentalItem::MAX_PHOTOS.' photos.',
        ];
    }

    public function save(): ?RentalItem
    {
        $this->reset('notice');
        $this->resetErrorBag('item');
        $this->validate($this->rules(), $this->messages());
        if (count($this->photos) + count($this->newPhotos) > RentalItem::MAX_PHOTOS) {
            $this->addError('newPhotos', 'Up to '.RentalItem::MAX_PHOTOS.' photos. Remove some first.');

            return null;
        }
        $existing = $this->item();
        $folder = ($this->sale() ? 'second-hand/' : 'rent-items/').auth()->id();
        $photos = $this->photos;
        foreach ($this->newPhotos as $file) {
            $photos[] = ImageShrinker::store($file, $folder, 'item_photo');
        }
        $text = fn (string $v) => trim($v) !== '' ? trim($v) : null;
        $fields = [
            'deal' => $this->deal,
            'name' => trim(preg_replace('/\s+/', ' ', $this->name)),
            'category' => $this->category,
            'condition' => $this->condition ?: null,
            'price' => round((float) $this->price, 2),
            'price_unit' => $this->sale() ? null : $this->priceUnit,
            'deposit' => ! $this->sale() && $this->deposit !== '' && (float) $this->deposit > 0 ? round((float) $this->deposit, 2) : null,
            'can_deliver' => $this->canDeliver,
            'negotiable' => $this->sale() && $this->negotiable,
            'has_bill' => $this->sale() && $this->hasBill,
            'about' => $text($this->about),
            'zone_id' => (int) $this->zoneId,
            'landmark' => $text($this->landmark),
            'photos' => array_values($photos),
        ];
        if ($this->cover) {
            $fields['cover_image'] = ImageShrinker::store($this->cover, $folder, 'item_photo');
        } elseif ($existing === null) {
            $fields['cover_image'] = null;
        }

        try {
            [$item, $backToReview] = app(RentalItemService::class)->save(auth()->user(), $existing, $fields);
        } catch (\RuntimeException $e) {
            $this->addError('item', $e->getMessage());

            return null;
        }

        $this->reset('cover', 'newPhotos');
        $what = $this->sale() ? 'Second-hand post' : 'Rent anything item';
        ActivityLogger::logModel(auth()->user(), $item, $existing ? $what.' updated' : $what.' added');
        $this->itemId = $item->id;
        $this->photos = array_values((array) $item->photos);
        $this->notice = $backToReview
            ? 'Saved. We will quickly check your change; the item shows again after that.'
            : ($item->status === 'approved' ? 'Saved. The new price shows at once.' : 'Saved.');

        return $item;
    }

    /** Save, then send for review. */
    public function submit(): void
    {
        $item = $this->save();
        if ($item === null || ! $item->isEditable()) {
            return;
        }
        try {
            app(RentalItemService::class)->submit(auth()->user(), $item);
        } catch (\RuntimeException $e) {
            $this->addError('item', $e->getMessage());

            return;
        }
        session()->flash(RentItems::noticeKeyFor($this->deal), 'Sent! We will check "'.$item->name.'" and put it up, usually within a day.');
        $this->redirectRoute(PostTypes::route($this->deal, 'mine'), navigate: true);
    }

    public function render()
    {
        $item = $this->item();
        $scope = $this->scope();

        return view('livewire.customer.account.rent-item-editor', [
            'item' => $item,
            'editable' => $item === null || $item->isEditable() || $item->status === 'approved',
            'blocked' => $item === null ? RentalItemService::cannotPostReason(auth()->user(), $scope, null, $this->deal) : null,
            'zones' => RentalItemService::postingZones($this->deal),
            'categories' => $this->choices($this->key('categories'), [(string) $item?->category]),
            'conditions' => $this->choices($this->key('conditions'), [(string) $item?->condition]),
            'billKinds' => $this->sale() ? RentalItemSettings::listIn('secondhand.bill_kinds', $scope) : [],
            'sale' => $this->sale(),
            'mineRoute' => PostTypes::route($this->deal, 'mine'),
            'units' => $this->choices('items.price_units', [(string) $item?->price_unit]),
            'minPhotos' => (int) RentalItemSettings::get('items.min_photos', $scope),
            'symbol' => '₹',
        ])->layout('components.layouts.customer', ['title' => $item ? 'Change '.$item->name : ($this->sale() ? 'Post a used thing' : 'Rent out something')]);
    }
}
