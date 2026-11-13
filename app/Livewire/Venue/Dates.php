<?php

namespace App\Livewire\Venue;

use App\Livewire\Venue\Concerns\InteractsWithHalls;
use App\Models\HallBlockedDate;
use App\Services\ActivityLogger;
use App\Services\TimezoneResolver;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Hall portal → Dates (Function halls R1): days the owner already sold outside 1CallFix (a walk-in booking, a family
 * function). A blocked day, or one slot of it, can never be booked by a customer. Works before approval too, so the
 * calendar is right on the first day.
 */
class Dates extends Component
{
    use InteractsWithHalls;

    public ?int $hallId = null;

    public string $date = '';
    /** '' = the whole day */
    public string $slotId = '';
    public string $note = '';

    public string $notice = '';

    public function mount(): void
    {
        $this->hallId = $this->halls()->first()?->id;
    }

    private function today(): string
    {
        return now(app(TimezoneResolver::class)->platformTimezone())->toDateString();
    }

    public function block(): void
    {
        $this->reset('notice');
        $hall = $this->ownHall((int) $this->hallId);
        $this->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->today()],
            'slotId' => ['nullable', 'in:'.implode(',', array_merge([''], $hall->slots->where('is_active', true)->pluck('id')->map(fn ($id) => (string) $id)->all()))],
            'note' => ['nullable', 'string', 'max:200'],
        ], ['date.after_or_equal' => 'Choose today or a later date.', 'date.required' => 'Choose a date.']);

        $slot = $this->slotId !== '' ? (int) $this->slotId : null;
        $clash = HallBlockedDate::where('hall_id', $hall->id)->whereDate('date', $this->date)
            ->where(fn ($q) => $q->whereNull('hall_slot_id')->when($slot, fn ($q) => $q->orWhere('hall_slot_id', $slot)))->exists();
        if ($clash) {
            $this->addError('date', 'Already blocked.');

            return;
        }
        $row = HallBlockedDate::create(['hall_id' => $hall->id, 'date' => $this->date, 'hall_slot_id' => $slot, 'note' => trim($this->note) ?: null]);
        ActivityLogger::logModel(auth()->user(), $hall, 'Date blocked (hall portal)', ['date' => $this->date, 'slot' => $row->slot?->name ?? 'whole day']);
        $this->reset('date', 'slotId', 'note');
        $this->notice = 'Blocked. Customers cannot book it.';
    }

    public function unblock(int $id): void
    {
        $hall = $this->ownHall((int) $this->hallId);
        $row = HallBlockedDate::where('hall_id', $hall->id)->findOrFail($id);
        ActivityLogger::logModel(auth()->user(), $hall, 'Date opened again (hall portal)', ['date' => $row->date->toDateString()]);
        $row->delete();
        $this->notice = 'Open for bookings again.';
    }

    public function render()
    {
        $halls = $this->halls();
        $hall = $halls->firstWhere('id', $this->hallId) ?? $halls->first();

        return view('livewire.venue.dates', [
            'halls' => $halls,
            'hall' => $hall,
            'slots' => $hall?->slots->where('is_active', true) ?? collect(),
            'blocked' => $hall ? HallBlockedDate::with('slot')->where('hall_id', $hall->id)->whereDate('date', '>=', $this->today())->orderBy('date')->get() : collect(),
            'today' => $this->today(),
        ])->layout('components.layouts.venue', ['title' => 'Dates']);
    }

    /** For the view: "Sat, 12 Dec 2026". */
    public static function dayLabel(Carbon $d): string
    {
        return $d->format('D, j M Y');
    }
}
