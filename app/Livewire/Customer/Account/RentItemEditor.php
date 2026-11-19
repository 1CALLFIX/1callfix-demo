<?php

namespace App\Livewire\Customer\Account;

use App\Models\RentalItem;
use App\Services\ActivityLogger;
use App\Services\RentalItems\RentalItemService;
use App\Support\ImageShrinker;
use App\Support\RentalItemSettings;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Account → Rent out your things → post / change an item (Rent anything, owner decision 2026-11-18). Four numbered,
 * coloured steps: photos, what it is, price, where. The person types only the name, the price and (optionally) a few
 * words about it; everything else is a tap. One Send / Save bar at the bottom. The team approves before customers see it.
 */
class RentItemEditor extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $itemId = null;

    public string $name = '';
    public string $category = '';
    public string $condition = '';
    public string $price = '';
    public string $priceUnit = '';
    public string $deposit = '';
    public bool $canDeliver = false;
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
            $this->about = (string) $existing->about;
            $this->zoneId = (string) $existing->zone_id;
            $this->landmark = (string) $existing->landmark;
            $this->photos = array_values((array) $existing->photos);

            return;
        }
        $zones = RentalItemService::postingZones();
        if ($zones->count() === 1) {
            $this->zoneId = (string) $zones->first()->id;
        }
        $this->priceUnit = $this->choices('items.price_units')[0] ?? '';
    }

    private function item(): ?RentalItem
    {
        return $this->itemId ? RentalItem::where('user_id', auth()->id())->find($this->itemId) : null;
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
            'category' => ['required', Rule::in($this->choices('items.categories', [(string) $item?->category]))],
            'condition' => ['nullable', Rule::in($this->choices('items.conditions', [(string) $item?->condition]))],
            'price' => ['required', 'numeric', 'min:'.(int) RentalItemSettings::get('items.min_price', $scope), 'max:'.(int) RentalItemSettings::get('items.max_price', $scope)],
            'priceUnit' => ['required', Rule::in($this->choices('items.price_units', [(string) $item?->price_unit]))],
            'deposit' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'about' => ['nullable', 'string', 'max:2000'],
            'zoneId' => ['required', Rule::in(RentalItemService::postingZones()->pluck('id')->push($item?->zone_id)->filter()->map(fn ($id) => (string) $id)->all())],
            'landmark' => ['nullable', 'string', 'max:160'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'newPhotos' => ['array', 'max:'.RentalItem::MAX_PHOTOS],
            'newPhotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];
    }

    private function messages(): array
    {
        return [
            'name.required' => 'Write what you rent out, e.g. "Sony camera" or "JBL party speaker".',
            'name.min' => 'Write what you rent out, e.g. "Sony camera".',
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
        $folder = 'rent-items/'.auth()->id();
        $photos = $this->photos;
        foreach ($this->newPhotos as $file) {
            $photos[] = ImageShrinker::store($file, $folder, 'item_photo');
        }
        $text = fn (string $v) => trim($v) !== '' ? trim($v) : null;
        $fields = [
            'name' => trim(preg_replace('/\s+/', ' ', $this->name)),
            'category' => $this->category,
            'condition' => $this->condition ?: null,
            'price' => round((float) $this->price, 2),
            'price_unit' => $this->priceUnit,
            'deposit' => $this->deposit !== '' && (float) $this->deposit > 0 ? round((float) $this->deposit, 2) : null,
            'can_deliver' => $this->canDeliver,
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
        ActivityLogger::logModel(auth()->user(), $item, $existing ? 'Rent anything item updated' : 'Rent anything item added');
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
        session()->flash('rent-items.notice', 'Sent! We will check "'.$item->name.'" and put it up, usually within a day.');
        $this->redirectRoute('customer.rent-items', navigate: true);
    }

    public function render()
    {
        $item = $this->item();
        $scope = $this->scope();

        return view('livewire.customer.account.rent-item-editor', [
            'item' => $item,
            'editable' => $item === null || $item->isEditable() || $item->status === 'approved',
            'blocked' => $item === null ? RentalItemService::cannotPostReason(auth()->user(), $scope) : null,
            'zones' => RentalItemService::postingZones(),
            'categories' => $this->choices('items.categories', [(string) $item?->category]),
            'conditions' => $this->choices('items.conditions', [(string) $item?->condition]),
            'units' => $this->choices('items.price_units', [(string) $item?->price_unit]),
            'minPhotos' => (int) RentalItemSettings::get('items.min_photos', $scope),
            'symbol' => '₹',
        ])->layout('components.layouts.customer', ['title' => $item ? 'Change '.$item->name : 'Rent out something']);
    }
}
