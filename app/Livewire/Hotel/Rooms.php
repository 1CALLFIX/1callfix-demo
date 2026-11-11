<?php

namespace App\Livewire\Hotel;

use App\Livewire\Hotel\Concerns\InteractsWithHotel;
use App\Models\HotelRatePlan;
use App\Models\HotelRoomType;
use App\Services\ActivityLogger;
use App\Support\ImageShrinker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Hotel portal → Rooms (Hotels H1). One card per kind of room (Deluxe AC, Standard non-AC…): how many such rooms the
 * hotel has, who fits, beds, the normal price per night with what is included, and photos. Each kind keeps one
 * "Standard" price (HotelRatePlan); a different price on chosen dates is set on the Calendar.
 */
class Rooms extends Component
{
    use InteractsWithHotel;
    use WithFileUploads;

    public const MAX_PHOTOS = 6;

    public const MEAL_PLANS = ['room_only' => 'Room only', 'breakfast' => 'With breakfast', 'half_board' => 'Breakfast + dinner', 'full_board' => 'All meals'];

    public ?int $editingId = null;
    public bool $showForm = false;
    public string $roomName = '';
    public string $roomDescription = '';
    public string $beds = '';
    public string $maxAdults = '2';
    public string $maxChildren = '0';
    public string $inventory = '1';
    public string $price = '';
    public string $mealPlan = 'room_only';
    /** @var array<int, mixed> */
    public array $newPhotos = [];

    public string $notice = '';

    public function add(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $roomTypeId): void
    {
        $room = $this->ownRoom($roomTypeId);
        $plan = $this->planFor($room);
        $this->resetForm();
        $this->editingId = $room->id;
        $this->roomName = (string) $room->name;
        $this->roomDescription = (string) $room->description;
        $this->beds = (string) $room->bed_configuration;
        $this->maxAdults = (string) $room->max_adults;
        $this->maxChildren = (string) $room->max_children;
        $this->inventory = (string) $room->total_inventory;
        $this->price = $plan ? rtrim(rtrim((string) $plan->nightly_rate, '0'), '.') : '';
        $this->mealPlan = $plan?->meal_plan ?? 'room_only';
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $this->reset('notice');
        $existing = $this->editingId ? $this->ownRoom($this->editingId) : null;
        $space = self::MAX_PHOTOS - count($existing?->photos ?? []);
        $this->validate([
            'roomName' => ['required', 'string', 'min:2', 'max:80'],
            'roomDescription' => ['nullable', 'string', 'max:1000'],
            'beds' => ['nullable', 'string', 'max:80'],
            'maxAdults' => ['required', 'integer', 'min:1', 'max:20'],
            'maxChildren' => ['required', 'integer', 'min:0', 'max:10'],
            'inventory' => ['required', 'integer', 'min:1', 'max:500'],
            'price' => ['required', 'numeric', 'min:1', 'max:500000'],
            'mealPlan' => ['required', Rule::in(array_keys(self::MEAL_PLANS))],
            'newPhotos' => ['array', 'max:'.max(0, $space)],
            'newPhotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], [
            'roomName.required' => 'Give this room a name, like "Deluxe AC".',
            'inventory.required' => 'How many rooms of this kind do you have?',
            'price.required' => 'Enter the price per night.',
            'newPhotos.max' => 'Up to '.self::MAX_PHOTOS.' photos per room. Remove one first.',
        ]);

        $hotel = $this->hotel();
        DB::transaction(function () use ($existing, $hotel) {
            $fields = [
                'name' => trim($this->roomName),
                'description' => trim($this->roomDescription) ?: null,
                'bed_configuration' => trim($this->beds) ?: null,
                'max_adults' => (int) $this->maxAdults,
                'max_children' => (int) $this->maxChildren,
                'total_inventory' => (int) $this->inventory,
            ];
            $room = $existing ?? HotelRoomType::create($fields + ['accommodation_id' => $hotel->id, 'is_active' => true]);
            if ($existing) {
                $room->update($fields);
            }
            if ($this->newPhotos !== []) {
                $paths = array_map(fn ($f) => ImageShrinker::store($f, 'hotels/'.$hotel->id.'/rooms', 'hotel_photo'), $this->newPhotos);
                $all = [...($room->photos ?? []), ...$paths];
                $room->update(['photos' => array_values($all), 'cover_image' => $room->cover_image ?: $all[0]]);
            }

            $plan = $this->planFor($room);
            $planFields = ['nightly_rate' => round((float) $this->price, 2), 'meal_plan' => $this->mealPlan, 'is_active' => true];
            $plan ? $plan->update($planFields) : HotelRatePlan::create($planFields + ['hotel_room_type_id' => $room->id, 'name' => 'Standard']);

            ActivityLogger::logModel(auth()->user(), $room, ($existing ? 'Room updated' : 'Room added').' (hotel portal)', ['price' => $this->price, 'rooms' => $this->inventory]);
        });

        $this->notice = $existing ? 'Room saved.' : 'Room added.';
        $this->resetForm();
    }

    public function toggle(int $roomTypeId): void
    {
        $room = $this->ownRoom($roomTypeId);
        $room->update(['is_active' => ! $room->is_active]);
        $this->notice = $room->is_active ? 'Room is on sale again.' : 'Room taken off sale.';
    }

    public function removePhoto(int $roomTypeId, int $index): void
    {
        $room = $this->ownRoom($roomTypeId);
        $photos = $room->photos ?? [];
        if (! array_key_exists($index, $photos)) {
            return;
        }
        $gone = $photos[$index];
        unset($photos[$index]);
        $photos = array_values($photos);
        $room->update(['photos' => $photos, 'cover_image' => $room->cover_image === $gone ? ($photos[0] ?? null) : $room->cover_image]);
    }

    private function planFor(HotelRoomType $room): ?HotelRatePlan
    {
        return HotelRatePlan::where('hotel_room_type_id', $room->id)->orderByDesc('is_active')->orderBy('id')->first();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'showForm', 'roomName', 'roomDescription', 'beds', 'maxAdults', 'maxChildren', 'inventory', 'price', 'mealPlan', 'newPhotos');
        $this->resetValidation();
    }

    public function render()
    {
        $hotel = $this->hotel();
        $rooms = HotelRoomType::where('accommodation_id', $hotel->id)
            ->with(['ratePlans' => fn ($q) => $q->orderByDesc('is_active')->orderBy('id')])->orderBy('id')->get();

        return view('livewire.hotel.rooms', [
            'rooms' => $rooms,
            'mealPlans' => self::MEAL_PLANS,
            'editing' => $this->editingId ? $rooms->firstWhere('id', $this->editingId) : null,
        ])->layout('components.layouts.hotel', ['title' => 'Rooms']);
    }
}
