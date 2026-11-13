<?php

namespace App\Livewire\Venue;

use App\Livewire\Venue\Concerns\InteractsWithHalls;
use App\Models\Hall;
use App\Models\HallSlot;
use App\Services\ActivityLogger;
use App\Support\HallSettings;
use App\Support\ImageShrinker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Hall portal → one hall (Function halls R1): kind, capacity, facilities, address, photos, rules, deposit and its slots
 * (Full day, Morning, Evening: time and price). Draft / Needs changes: everything changes at once, then "Send for
 * review". Waiting for review: locked. Approved: prices, slots, capacity, facilities, rules and deposit change at once;
 * name, address, map pin, photos and description wait for the team while the hall stays bookable with the old ones.
 */
class HallEditor extends Component
{
    use InteractsWithHalls;
    use WithFileUploads;

    public const MAX_SLOTS = 6;

    #[Locked]
    public ?int $hallId = null;

    public string $name = '';
    public string $hallType = '';
    public string $about = '';
    public string $addressLine = '';
    public string $lat = '';
    public string $lng = '';
    public string $seating = '';
    public string $floating = '';
    public bool $isAc = false;
    public string $parking = '';
    public string $rooms = '';
    /** @var list<string> */
    public array $amenities = [];
    public string $rules = '';
    public string $deposit = '';
    public string $gstNumber = '';

    public $cover = null;
    /** @var array<int, mixed> */
    public array $newPhotos = [];
    /** Photo paths shown on screen (pending ones for a live hall with a change waiting). @var list<string> */
    public array $photos = [];

    /** @var list<array{id: int|null, name: string, start_time: string, end_time: string, price: string}> */
    public array $hallSlots = [];

    public string $notice = '';

    public function mount(?int $hallId = null): void
    {
        if ($hallId !== null) {
            $hall = $this->ownHall($hallId);
            // A live hall with a change waiting shows the owner what they sent (customers still see the old details).
            $v = fn (string $field) => array_key_exists($field, (array) $hall->pending_changes) ? $hall->pending_changes[$field] : $hall->{$field};
            $this->hallId = $hall->id;
            $this->name = (string) $v('name');
            $this->hallType = (string) $hall->hall_type;
            $this->about = (string) $v('about');
            $this->addressLine = (string) $v('address_line');
            $this->lat = $v('lat') !== null ? (string) (float) $v('lat') : '';
            $this->lng = $v('lng') !== null ? (string) (float) $v('lng') : '';
            $this->seating = $hall->seating_capacity ? (string) $hall->seating_capacity : '';
            $this->floating = $hall->floating_capacity ? (string) $hall->floating_capacity : '';
            $this->isAc = (bool) $hall->is_ac;
            $this->parking = $hall->parking_cars !== null ? (string) $hall->parking_cars : '';
            $this->rooms = $hall->rooms !== null ? (string) $hall->rooms : '';
            $this->amenities = array_values((array) ($hall->amenities ?? []));
            $this->rules = (string) $hall->rules;
            $this->deposit = $hall->deposit_amount !== null ? (string) (float) $hall->deposit_amount : '';
            $this->gstNumber = (string) $hall->gst_number;
            $this->photos = array_values((array) ($v('photos') ?? []));
            $this->hallSlots = $hall->slots->map(fn (HallSlot $s) => [
                'id' => $s->id, 'name' => $s->name, 'start_time' => substr((string) $s->start_time, 0, 5),
                'end_time' => substr((string) $s->end_time, 0, 5), 'price' => (string) (float) $s->price,
            ])->all();
        }
        if ($this->hallSlots === []) {
            $this->hallSlots = [
                ['id' => null, 'name' => 'Full day', 'start_time' => '06:00', 'end_time' => '23:00', 'price' => ''],
            ];
        }
    }

    private function hall(): ?Hall
    {
        return $this->hallId ? $this->ownHall($this->hallId) : null;
    }

    public function addSlot(): void
    {
        if (count($this->hallSlots) < self::MAX_SLOTS) {
            $this->hallSlots[] = ['id' => null, 'name' => '', 'start_time' => '', 'end_time' => '', 'price' => ''];
        }
    }

    /** One tap adds a ready slot with usual times (owner 2026-11-13: easy for every vendor); the owner only types the price. */
    public const PRESETS = [
        'full' => ['Full day', '06:00', '23:00'],
        'morning' => ['Morning', '06:00', '14:00'],
        'evening' => ['Evening', '17:00', '23:00'],
    ];

    public function addPreset(string $which): void
    {
        $preset = self::PRESETS[$which] ?? null;
        if ($preset === null || count($this->hallSlots) >= self::MAX_SLOTS) {
            return;
        }
        // Replace the empty first row instead of adding a second one.
        $rows = array_values(array_filter($this->hallSlots, fn ($r) => filled($r['id']) || filled($r['price']) || ! in_array($r['name'], ['', 'Full day'], true)));
        if (collect($rows)->contains(fn ($r) => strcasecmp(trim($r['name']), $preset[0]) === 0)) {
            $this->hallSlots = $rows ?: $this->hallSlots;

            return;
        }
        $rows[] = ['id' => null, 'name' => $preset[0], 'start_time' => $preset[1], 'end_time' => $preset[2], 'price' => ''];
        $this->hallSlots = $rows;
    }

    public function removeSlot(int $index): void
    {
        unset($this->hallSlots[$index]);
        $this->hallSlots = array_values($this->hallSlots);
    }

    public function removePhoto(int $index): void
    {
        unset($this->photos[$index]);
        $this->photos = array_values($this->photos);
    }

    private function hallRules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'hallType' => ['required', Rule::in(HallSettings::lines('hall.types'))],
            'about' => ['nullable', 'string', 'max:5000'],
            'addressLine' => ['required', 'string', 'min:6', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'seating' => ['required', 'integer', 'min:10', 'max:100000'],
            'floating' => ['nullable', 'integer', 'min:10', 'max:100000'],
            'parking' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'rooms' => ['nullable', 'integer', 'min:0', 'max:200'],
            'amenities' => ['array'],
            'amenities.*' => [Rule::in(HallSettings::lines('hall.amenities'))],
            'rules' => ['nullable', 'string', 'max:3000'],
            'deposit' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'gstNumber' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z0-9]{13}$/'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'newPhotos' => ['array', 'max:'.Hall::MAX_PHOTOS],
            'newPhotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'hallSlots' => ['required', 'array', 'min:1', 'max:'.self::MAX_SLOTS],
            'hallSlots.*.name' => ['required', 'string', 'min:2', 'max:60', 'distinct:ignore_case'],
            'hallSlots.*.start_time' => ['required', 'date_format:H:i'],
            'hallSlots.*.end_time' => ['required', 'date_format:H:i', 'different:hallSlots.*.start_time'],
            'hallSlots.*.price' => ['required', 'numeric', 'min:1', 'max:10000000'],
        ];
    }

    private function hallMessages(): array
    {
        return [
            'hallType.required' => 'Choose what kind of hall it is.',
            'hallType.in' => 'Choose what kind of hall it is.',
            'seating.required' => 'How many people can sit?',
            'seating.min' => 'Enter the seating capacity (at least 10).',
            'gstNumber.regex' => 'A GST number is 15 characters, like 37ABCDE1234F1Z5.',
            'hallSlots.required' => 'Add at least one slot.',
            'hallSlots.*.name.required' => 'Name this slot, e.g. Full day, Morning, Evening.',
            'hallSlots.*.name.distinct' => 'Two slots have the same name.',
            'hallSlots.*.start_time.required' => 'When does it start?',
            'hallSlots.*.end_time.required' => 'When does it end?',
            'hallSlots.*.end_time.different' => 'Start and end cannot be the same time.',
            'hallSlots.*.price.required' => 'Enter the price for this slot.',
            'hallSlots.*.price.min' => 'The lowest price is ₹1.',
            'newPhotos.max' => 'Up to '.Hall::MAX_PHOTOS.' photos.',
        ];
    }

    /** Save. Returns the hall, or null when it cannot be changed right now. */
    public function save(): ?Hall
    {
        $this->reset('notice');
        $existing = $this->hall();
        if ($existing?->status === 'in_review') {
            $this->addError('hall', 'We are checking this hall. You can change it again once we reply.');

            return null;
        }
        $this->validate($this->hallRules(), $this->hallMessages());
        if (count($this->photos) + count($this->newPhotos) > Hall::MAX_PHOTOS) {
            $this->addError('newPhotos', 'Up to '.Hall::MAX_PHOTOS.' photos. Remove some first.');

            return null;
        }

        $partner = $this->partner();
        $folder = 'halls/'.($existing?->id ?? 'new-'.$partner->id);
        $cover = $this->cover ? ImageShrinker::store($this->cover, $folder, 'hall_photo') : null;
        $photos = $this->photos;
        foreach ($this->newPhotos as $file) {
            $photos[] = ImageShrinker::store($file, $folder, 'hall_photo');
        }

        // Reviewed on a live hall: name, about, address, pin, photos.
        $reviewed = [
            'name' => trim($this->name),
            'about' => trim($this->about) ?: null,
            'address_line' => trim($this->addressLine),
            'lat' => $this->lat !== '' ? round((float) $this->lat, 7) : null,
            'lng' => $this->lng !== '' ? round((float) $this->lng, 7) : null,
            'photos' => array_values($photos),
        ] + ($cover ? ['cover_image' => $cover] : []);
        // At once, always.
        $now = [
            'hall_type' => $this->hallType,
            'seating_capacity' => (int) $this->seating,
            'floating_capacity' => $this->floating !== '' ? (int) $this->floating : null,
            'is_ac' => $this->isAc,
            'parking_cars' => $this->parking !== '' ? (int) $this->parking : null,
            'rooms' => $this->rooms !== '' ? (int) $this->rooms : null,
            'amenities' => array_values(array_unique($this->amenities)),
            'rules' => trim($this->rules) ?: null,
            'deposit_amount' => $this->deposit !== '' && (float) $this->deposit > 0 ? round((float) $this->deposit, 2) : null,
            'gst_number' => filled($this->gstNumber) ? strtoupper(trim($this->gstNumber)) : null,
        ];

        $waiting = false;
        $hall = DB::transaction(function () use ($existing, $partner, $reviewed, $now, &$waiting) {
            if ($existing === null) {
                $first = $this->halls()->first();
                $hall = Hall::create($reviewed + $now + [
                    'provider_id' => $partner->id,
                    'franchise_id' => $first?->franchise_id ?? $partner->franchise_id,
                    'zone_id' => $first?->zone_id ?? $partner->zone_id,
                    'slug' => Hall::uniqueSlug($reviewed['name']),
                    'contact_phone' => $partner->user?->phone,
                    'status' => 'draft',
                    'is_active' => false,
                ]);
            } elseif ($existing->status === 'approved') {
                $existing->update($now);
                $wait = [];
                foreach ($reviewed as $field => $value) {
                    if (! self::same($existing->getAttribute($field), $value)) {
                        $wait[$field] = $value;
                    }
                }
                if ($wait !== [] || $existing->pending_changes !== null) {
                    // What the owner sees now is the whole waiting set (a newer change replaces an older one).
                    $existing->update(['pending_changes' => $wait ?: null, 'pending_requested_at' => $wait ? now() : null, 'pending_rejection' => null]);
                    $waiting = $wait !== [];
                }
                $hall = $existing;
            } else {
                $fields = $reviewed + $now;
                if ($existing->name !== $fields['name']) {
                    $fields['slug'] = Hall::uniqueSlug($fields['name'], $existing->id);
                }
                $existing->update($fields);
                $hall = $existing;
            }

            // Prices and slots change at once (bookings already made keep the price they paid).
            $keep = [];
            foreach (array_values($this->hallSlots) as $i => $row) {
                $values = [
                    'name' => trim($row['name']),
                    'start_time' => $row['start_time'].':00',
                    'end_time' => $row['end_time'].':00',
                    'price' => round((float) $row['price'], 2),
                    'sort_order' => $i,
                    'is_active' => true,
                ];
                $slot = $row['id'] ? $hall->slots()->whereKey($row['id'])->first() : null;
                $slot ? $slot->update($values) : $slot = $hall->slots()->create($values);
                $keep[] = $slot->id;
            }
            // A slot taken off is switched off, not deleted: blocked dates (and R2 bookings) keep pointing at it.
            $hall->slots()->whereNotIn('id', $keep)->update(['is_active' => false]);

            return $hall;
        });

        $this->reset('cover', 'newPhotos');
        ActivityLogger::logModel(auth()->user(), $hall, $existing ? 'Hall updated (hall portal)' : 'Hall added (hall portal)', $waiting ? ['waiting_for_review' => array_keys((array) $hall->pending_changes)] : []);
        $this->hallId = $hall->id;
        $this->mount($hall->id);
        $this->notice = $waiting ? 'Saved. Prices and slots changed now; the new name, address, photos or description show once we check them (customers see the old ones meanwhile).' : 'Saved.';

        return $hall;
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

    /** Send for review: the hall is complete. */
    public function submit(): void
    {
        $hall = $this->save();
        if ($hall === null) {
            return;
        }
        if (! $hall->isEditable()) {
            return; // approved: changes already handled by save()
        }
        $hall->refresh()->load('slots');
        $problems = self::submitProblems($hall);
        if ($problems !== []) {
            $this->addError('hall', implode(' ', $problems));
            $this->notice = 'Saved as a draft.';

            return;
        }

        $hall->update(['status' => 'in_review', 'submitted_at' => now(), 'rejection_reason' => null]);
        ActivityLogger::logModel(auth()->user(), $hall, 'Hall sent for review (hall portal)');
        $this->notice = 'Sent for review. We check it and tell you here, usually within a day.';
    }

    /** @return list<string> what stops this hall from being sent for review / approved */
    public static function submitProblems(Hall $hall): array
    {
        $problems = [];
        if ($hall->cover_image === null) {
            $problems[] = 'Add a cover photo.';
        }
        if (count((array) $hall->photos) < 3) {
            $problems[] = 'Add at least 3 more photos (the hall, the stage, the dining area).';
        }
        if ($hall->slots()->where('is_active', true)->doesntExist()) {
            $problems[] = 'Add at least one slot with its price.';
        }
        if (! $hall->seating_capacity || ! filled($hall->hall_type)) {
            $problems[] = 'Fill in the kind of hall and how many people can sit.';
        }

        return $problems;
    }

    /** Approved halls: the owner switches bookings on or off (e.g. under repair). */
    public function toggleActive(): void
    {
        $hall = $this->hall();
        if ($hall === null || $hall->status !== 'approved') {
            return;
        }
        $hall->update(['is_active' => ! $hall->is_active]);
        ActivityLogger::logModel(auth()->user(), $hall, $hall->is_active ? 'Hall switched on (hall portal)' : 'Hall switched off (hall portal)');
        $this->notice = $hall->is_active ? 'Your hall takes bookings again.' : 'Your hall is hidden from customers until you switch it on.';
    }

    /** Withdraw a change still waiting for the team. */
    public function withdrawChanges(): void
    {
        $hall = $this->hall();
        if ($hall === null || $hall->pending_changes === null) {
            return;
        }
        $hall->update(['pending_changes' => null, 'pending_requested_at' => null]);
        ActivityLogger::logModel(auth()->user(), $hall, 'Waiting hall change withdrawn (hall portal)');
        $this->mount($hall->id);
        $this->notice = 'Change withdrawn.';
    }

    public function deleteDraft(): void
    {
        $hall = $this->hall();
        if ($hall === null || $hall->status !== 'draft' || $this->halls()->count() < 2) {
            return; // the last hall stays: it is what makes this account a hall owner
        }
        ActivityLogger::logModel(auth()->user(), $hall, 'Draft hall deleted (hall portal)');
        $hall->delete();
        $this->redirectRoute('venue.dashboard', navigate: true);
    }

    public function render()
    {
        $hall = $this->hall();

        return view('livewire.venue.hall-editor', [
            'hall' => $hall,
            'editable' => $hall === null || $hall->status !== 'in_review',
            'types' => HallSettings::lines('hall.types'),
            'amenityList' => HallSettings::lines('hall.amenities'),
            'canDelete' => $hall?->status === 'draft' && $this->halls()->count() > 1,
        ])->layout('components.layouts.venue', ['title' => $hall ? $hall->name : 'Add a hall']);
    }
}
