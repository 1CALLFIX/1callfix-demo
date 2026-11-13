<?php

namespace App\Livewire\Organiser;

use App\Livewire\Organiser\Concerns\InteractsWithOrganiser;
use App\Models\Event;
use App\Models\EventTicketType;
use App\Services\ActivityLogger;
use App\Services\TimezoneResolver;
use App\Support\EventSettings;
use App\Support\ImageShrinker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Organiser portal → one event (Events E1): title, kind, about, venue, start / end, age limit, terms, cover photo and
 * its ticket types (Early Bird, Regular, VIP, Couple...: price, how many, people per ticket, most per order, sales end).
 * The organiser saves a draft and sends it for review; the 1CallFix team approves it before tickets can be sold.
 * While in review or approved the organiser cannot change it (a "Needs changes" event is editable again).
 */
class EventEditor extends Component
{
    use InteractsWithOrganiser;
    use WithFileUploads;

    public const MAX_TICKET_TYPES = 10;

    #[Locked]
    public ?int $eventId = null;

    public string $title = '';
    public string $category = '';
    public string $description = '';
    public string $venueName = '';
    public string $venueAddress = '';
    public string $lat = '';
    public string $lng = '';
    /** Local date-times as typed (Y-m-d\TH:i, platform time zone). */
    public string $startsAt = '';
    public string $endsAt = '';
    public string $minAge = '';
    public string $terms = '';

    public $cover = null;

    /** @var list<array{id: int|null, name: string, description: string, price: string, quantity: string, admits: string, max_per_order: string, sale_ends_at: string}> */
    public array $tickets = [];

    public string $notice = '';

    public function mount(?int $eventId = null): void
    {
        if ($eventId !== null) {
            $event = $this->ownEvent($eventId);
            $tz = $this->tz();
            $this->eventId = $event->id;
            $this->title = (string) $event->title;
            $this->category = (string) $event->category;
            $this->description = (string) $event->description;
            $this->venueName = (string) $event->venue_name;
            $this->venueAddress = (string) $event->venue_address;
            $this->lat = $event->lat !== null ? (string) $event->lat : '';
            $this->lng = $event->lng !== null ? (string) $event->lng : '';
            $this->startsAt = $event->starts_at->timezone($tz)->format('Y-m-d\TH:i');
            $this->endsAt = $event->ends_at->timezone($tz)->format('Y-m-d\TH:i');
            $this->minAge = $event->min_age ? (string) $event->min_age : '';
            $this->terms = (string) $event->terms;
            $this->tickets = $event->ticketTypes->map(fn (EventTicketType $t) => [
                'id' => $t->id, 'name' => $t->name, 'description' => (string) $t->description, 'price' => (string) (float) $t->price,
                'quantity' => (string) $t->quantity, 'admits' => (string) $t->admits, 'max_per_order' => (string) $t->max_per_order,
                'sale_ends_at' => $t->sale_ends_at?->timezone($tz)->format('Y-m-d\TH:i') ?? '',
            ])->all();
        }
        if ($this->tickets === []) {
            $this->addTicket();
        }
    }

    private function tz(): string
    {
        return app(TimezoneResolver::class)->platformTimezone();
    }

    private function event(): ?Event
    {
        return $this->eventId ? $this->ownEvent($this->eventId) : null;
    }

    public function addTicket(): void
    {
        if (count($this->tickets) >= self::MAX_TICKET_TYPES) {
            return;
        }
        $this->tickets[] = ['id' => null, 'name' => $this->tickets === [] ? 'Regular' : '', 'description' => '', 'price' => '', 'quantity' => '',
            'admits' => '1', 'max_per_order' => (string) min(10, (int) EventSettings::get('event.max_tickets_per_order')), 'sale_ends_at' => ''];
    }

    public function removeTicket(int $index): void
    {
        unset($this->tickets[$index]);
        $this->tickets = array_values($this->tickets);
    }

    private function eventRules(): array
    {
        $maxPerOrder = (int) EventSettings::get('event.max_tickets_per_order');

        return [
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'category' => ['required', Rule::in(EventSettings::lines('event.categories'))],
            'description' => ['nullable', 'string', 'max:5000'],
            'venueName' => ['required', 'string', 'min:2', 'max:150'],
            'venueAddress' => ['required', 'string', 'min:6', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'startsAt' => ['required', 'date_format:Y-m-d\TH:i'],
            'endsAt' => ['required', 'date_format:Y-m-d\TH:i', 'after:startsAt'],
            'minAge' => ['nullable', Rule::in(['', '18', '21'])],
            'terms' => ['nullable', 'string', 'max:3000'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'tickets' => ['required', 'array', 'min:1', 'max:'.self::MAX_TICKET_TYPES],
            'tickets.*.name' => ['required', 'string', 'min:2', 'max:80', 'distinct:ignore_case'],
            'tickets.*.description' => ['nullable', 'string', 'max:300'],
            'tickets.*.price' => ['required', 'numeric', 'min:1', 'max:100000'],
            'tickets.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'tickets.*.admits' => ['required', 'integer', 'min:1', 'max:10'],
            'tickets.*.max_per_order' => ['required', 'integer', 'min:1', 'max:'.$maxPerOrder],
            'tickets.*.sale_ends_at' => ['nullable', 'date_format:Y-m-d\TH:i', 'before_or_equal:endsAt'],
        ];
    }

    private function eventMessages(): array
    {
        return [
            'endsAt.after' => 'The event must end after it starts.',
            'tickets.required' => 'Add at least one ticket type.',
            'tickets.*.name.required' => 'Name this ticket, e.g. Early Bird, Regular, VIP.',
            'tickets.*.name.distinct' => 'Two tickets have the same name.',
            'tickets.*.price.required' => 'Enter the ticket price.',
            'tickets.*.price.min' => 'The lowest ticket price is ₹1.',
            'tickets.*.quantity.required' => 'How many of these tickets can be sold?',
            'tickets.*.max_per_order.max' => 'At most :max tickets in one order.',
            'tickets.*.sale_ends_at.before_or_equal' => 'Sales must end before the event ends.',
            'category.required' => 'Choose what kind of event it is.',
            'category.in' => 'Choose what kind of event it is.',
        ];
    }

    /** Save as a draft (or keep a "Needs changes" event editable). Returns the event, or null when it cannot be changed. */
    public function save(): ?Event
    {
        $this->reset('notice');
        $existing = $this->event();
        if ($existing && ! $existing->isEditable()) {
            $this->addError('event', 'This event is '.strtolower($existing->statusLabel()).' and cannot be changed. Call us if something is wrong.');

            return null;
        }
        $this->validate($this->eventRules(), $this->eventMessages());

        $tz = $this->tz();
        $organiser = $this->organiser();
        $event = DB::transaction(function () use ($existing, $organiser, $tz) {
            $fields = [
                'title' => trim($this->title),
                'category' => $this->category,
                'description' => trim($this->description) ?: null,
                'venue_name' => trim($this->venueName),
                'venue_address' => trim($this->venueAddress),
                'lat' => $this->lat !== '' ? (float) $this->lat : null,
                'lng' => $this->lng !== '' ? (float) $this->lng : null,
                'starts_at' => Carbon::createFromFormat('Y-m-d\TH:i', $this->startsAt, $tz)->utc(),
                'ends_at' => Carbon::createFromFormat('Y-m-d\TH:i', $this->endsAt, $tz)->utc(),
                'min_age' => $this->minAge !== '' ? (int) $this->minAge : null,
                'terms' => trim($this->terms) ?: null,
            ];
            if ($existing) {
                $fields['slug'] = $existing->title === $fields['title'] ? $existing->slug : Event::uniqueSlug($fields['title'], $existing->id);
                $existing->update($fields);
                $event = $existing;
            } else {
                $event = Event::create($fields + [
                    'event_organiser_id' => $organiser->id,
                    'franchise_id' => $organiser->franchise_id,
                    'zone_id' => $organiser->zone_id,
                    'slug' => Event::uniqueSlug($fields['title']),
                    'status' => 'draft',
                ]);
            }

            // No ticket is sold before approval, so the list is simply replaced by what is on screen.
            $keep = [];
            foreach (array_values($this->tickets) as $i => $row) {
                $values = [
                    'name' => trim($row['name']),
                    'description' => trim((string) $row['description']) ?: null,
                    'price' => round((float) $row['price'], 2),
                    'quantity' => (int) $row['quantity'],
                    'admits' => (int) $row['admits'],
                    'max_per_order' => (int) $row['max_per_order'],
                    'sale_ends_at' => filled($row['sale_ends_at']) ? Carbon::createFromFormat('Y-m-d\TH:i', $row['sale_ends_at'], $tz)->utc() : null,
                    'sort_order' => $i,
                    'is_active' => true,
                ];
                $type = $row['id'] ? $event->ticketTypes()->whereKey($row['id'])->first() : null;
                $type ? $type->update($values) : $type = $event->ticketTypes()->create($values);
                $keep[] = $type->id;
            }
            $event->ticketTypes()->whereNotIn('id', $keep)->delete();

            return $event;
        });

        if ($this->cover) {
            $event->update(['cover_image' => ImageShrinker::store($this->cover, 'events/'.$event->id, 'event_cover')]);
            $this->reset('cover');
        }

        ActivityLogger::logModel(auth()->user(), $event, $existing ? 'Event updated (organiser portal)' : 'Event created (organiser portal)');
        $this->eventId = $event->id;
        $this->mount($event->id);
        $this->notice = 'Saved.';

        return $event;
    }

    /** Send for review: the event is complete, in the future, and the organiser is not turned down. */
    public function submit(): void
    {
        $event = $this->save();
        if ($event === null) {
            return;
        }
        $event->refresh();
        $problems = self::submitProblems($event);
        if ($problems !== []) {
            $this->addError('event', implode(' ', $problems));
            $this->notice = 'Saved as a draft.';

            return;
        }

        $event->update(['status' => 'in_review', 'submitted_at' => now(), 'rejection_reason' => null]);
        ActivityLogger::logModel(auth()->user(), $event, 'Event sent for review (organiser portal)');
        $this->notice = 'Sent for review. We check it and tell you here, usually within a day.';
    }

    /** @return list<string> what stops this event from being sent for review / approved */
    public static function submitProblems(Event $event): array
    {
        $problems = [];
        if ($event->cover_image === null) {
            $problems[] = 'Add a cover photo.';
        }
        if (! $event->ticketTypes()->exists()) {
            $problems[] = 'Add at least one ticket type.';
        }
        if ($event->starts_at === null || $event->starts_at->isPast()) {
            $problems[] = 'The start time has already passed.';
        }
        if ($event->organiser?->approval_status === 'rejected') {
            $problems[] = 'Your organiser account is not approved yet.';
        }

        return $problems;
    }

    public function deleteDraft(): void
    {
        $event = $this->event();
        if ($event === null || $event->status !== 'draft') {
            return;
        }
        ActivityLogger::logModel(auth()->user(), $event, 'Draft event deleted (organiser portal)');
        $event->delete();
        $this->redirectRoute('organiser.events', navigate: true);
    }

    public function render()
    {
        $event = $this->event();

        return view('livewire.organiser.event-editor', [
            'event' => $event,
            'editable' => $event === null || $event->isEditable(),
            'categories' => EventSettings::lines('event.categories'),
            'maxPerOrder' => (int) EventSettings::get('event.max_tickets_per_order'),
        ])->layout('components.layouts.organiser', ['title' => $event ? $event->title : 'New event']);
    }
}
