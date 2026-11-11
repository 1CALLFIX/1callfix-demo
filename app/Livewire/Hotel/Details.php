<?php

namespace App\Livewire\Hotel;

use App\Livewire\Hotel\Concerns\InteractsWithHotel;
use App\Services\ActivityLogger;
use App\Support\HotelSettings;
use App\Support\ImageShrinker;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Hotel portal → Hotel (Hotels H1): what guests see — name, about, address and map pin, check-in / check-out times,
 * phone, GST number, house rules, facilities (the Super Admin list) and photos (cover + up to 10 more).
 */
class Details extends Component
{
    use InteractsWithHotel;
    use WithFileUploads;

    public const MAX_PHOTOS = 10;

    public string $name = '';
    public string $description = '';
    public string $addressLine = '';
    public string $lat = '';
    public string $lng = '';
    public string $contactPhone = '';
    public string $gstNumber = '';
    public string $checkInFrom = '14:00';
    public string $checkOutBy = '11:00';
    public string $policies = '';
    /** @var list<string> */
    public array $facilities = [];

    public $cover = null;
    /** @var array<int, mixed> */
    public array $newPhotos = [];

    public string $notice = '';

    public function mount(): void
    {
        $h = $this->hotel();
        $this->name = (string) $h->name;
        $this->description = (string) $h->description;
        $this->addressLine = (string) $h->address_line;
        $this->lat = (string) $h->lat;
        $this->lng = (string) $h->lng;
        $this->contactPhone = (string) $h->contact_phone;
        $this->gstNumber = (string) $h->gst_number;
        $this->checkInFrom = substr((string) $h->check_in_time_start, 0, 5) ?: '14:00';
        $this->checkOutBy = substr((string) $h->check_out_time, 0, 5) ?: '11:00';
        $this->policies = (string) $h->policies;
        $this->facilities = array_values($h->facilities ?? []);
    }

    public function save(): void
    {
        $this->reset('notice');
        $allowed = HotelSettings::lines('hotel.amenities');
        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'addressLine' => ['required', 'string', 'min:6', 'max:500'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'contactPhone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+][0-9 \-]*$/'],
            'gstNumber' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z0-9]{13}$/'],
            'checkInFrom' => ['required', 'date_format:H:i'],
            'checkOutBy' => ['required', 'date_format:H:i'],
            'policies' => ['nullable', 'string', 'max:2000'],
            'facilities' => ['array'],
            'facilities.*' => ['string', \Illuminate\Validation\Rule::in($allowed)],
        ], [
            'gstNumber.regex' => 'A GST number is 15 characters, like 37ABCDE1234F1Z5.',
            'lat.required' => 'Pin your hotel on the map.',
        ]);

        $hotel = $this->hotel();
        $hotel->update([
            'name' => trim($this->name),
            'description' => trim($this->description) ?: null,
            'address_line' => trim($this->addressLine),
            'lat' => (float) $this->lat,
            'lng' => (float) $this->lng,
            'contact_phone' => trim($this->contactPhone) ?: null,
            'gst_number' => strtoupper(trim($this->gstNumber)) ?: null,
            'check_in_time_start' => $this->checkInFrom.':00',
            'check_out_time' => $this->checkOutBy.':00',
            'policies' => trim($this->policies) ?: null,
            'facilities' => array_values(array_intersect($allowed, $this->facilities)),
        ]);
        ActivityLogger::logModel(auth()->user(), $hotel, 'Hotel details updated (hotel portal)');
        $this->notice = 'Saved.';
    }

    public function savePhotos(): void
    {
        $this->reset('notice');
        $hotel = $this->hotel();
        $room = self::MAX_PHOTOS - count($hotel->photos ?? []);
        $this->validate([
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'newPhotos' => ['array', 'max:'.max(0, $room)],
            'newPhotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], ['newPhotos.max' => 'Up to '.self::MAX_PHOTOS.' photos besides the cover. Remove one first.']);

        $fields = [];
        if ($this->cover) {
            $fields['cover_image'] = ImageShrinker::store($this->cover, 'hotels/'.$hotel->id, 'hotel_photo');
        }
        if ($this->newPhotos !== []) {
            $fields['photos'] = array_values([...($hotel->photos ?? []), ...array_map(fn ($f) => ImageShrinker::store($f, 'hotels/'.$hotel->id, 'hotel_photo'), $this->newPhotos)]);
        }
        if ($fields !== []) {
            $hotel->update($fields);
            ActivityLogger::logModel(auth()->user(), $hotel, 'Hotel photos added (hotel portal)');
            $this->notice = 'Photos saved.';
        }
        $this->reset('cover', 'newPhotos');
    }

    public function removePhoto(int $index): void
    {
        $hotel = $this->hotel();
        $photos = $hotel->photos ?? [];
        if (! array_key_exists($index, $photos)) {
            return;
        }
        $path = $photos[$index];
        unset($photos[$index]);
        $hotel->update(['photos' => array_values($photos)]);
        if (! str_starts_with((string) $path, 'http')) {
            Storage::disk('public')->delete($path);
        }
        $this->notice = 'Photo removed.';
    }

    public function render()
    {
        $hotel = $this->hotel();

        return view('livewire.hotel.details', [
            'hotel' => $hotel,
            'allFacilities' => HotelSettings::lines('hotel.amenities'),
            'photoUrls' => array_map(fn ($p) => str_starts_with((string) $p, 'http') ? $p : Storage::disk('public')->url($p), $hotel->photos ?? []),
        ])->layout('components.layouts.hotel', ['title' => 'Hotel']);
    }
}
