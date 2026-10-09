<?php

namespace App\Livewire\Providers;

use App\Models\ProviderShift;
use App\Services\ActivityLogger;
use App\Services\Providers\ShiftSchedule as S;
use App\Services\SettingsAuditor;
use App\Support\SuperAdminGate;
use Livewire\Component;

/**
 * Admin → Providers → Availability & shifts. The auto-offline time, the shift mode (off / reminder / required), the
 * reminder lead time and grace, and the shift slots providers choose from (Swiggy-style). Super Admin only (checked in
 * mount() and every action); settings go through SettingsAuditor, slot changes are written to the activity log.
 * Nothing changes for providers until the mode is moved off "off".
 */
class Availability extends Component
{
    public string $staleMinutes = '30';
    public string $mode = 'off';
    public string $reminderMinutes = '10';
    public string $graceMinutes = '15';

    public ?int $editingId = null;
    public string $shiftName = '';
    public string $shiftStart = '08:00';
    public string $shiftEnd = '14:00';
    /** @var array<int, string> weekday numbers 0-6; empty = every day */
    public array $shiftDays = [];
    public bool $shiftActive = true;

    public string $flashMessage = '';

    public function mount(): void
    {
        $this->authorizeAdmin();

        $this->staleMinutes = (string) S::staleMinutes();
        $this->mode = S::mode();
        $this->reminderMinutes = (string) S::reminderMinutes();
        $this->graceMinutes = (string) S::graceMinutes();
    }

    public function saveSettings(): void
    {
        $this->authorizeAdmin();

        $this->validate([
            'staleMinutes' => ['required', 'integer', 'between:5,240'],
            'mode' => ['required', 'in:'.implode(',', S::MODES)],
            'reminderMinutes' => ['required', 'integer', 'between:0,240'],
            'graceMinutes' => ['required', 'integer', 'between:0,240'],
        ], [], ['staleMinutes' => 'auto-offline minutes', 'reminderMinutes' => 'reminder minutes', 'graceMinutes' => 'grace minutes']);

        $admin = auth()->user();
        $changed = false;
        foreach ([
            S::STALE_MINUTES => (string) (int) $this->staleMinutes,
            S::MODE => $this->mode,
            S::REMINDER_MINUTES => (string) (int) $this->reminderMinutes,
            S::GRACE_MINUTES => (string) (int) $this->graceMinutes,
        ] as $key => $value) {
            $changed = SettingsAuditor::put($admin, $key, $value) || $changed;
        }

        $this->flashMessage = $changed ? 'Availability settings saved.' : 'Nothing changed.';
    }

    public function editShift(int $id): void
    {
        $this->authorizeAdmin();

        $shift = ProviderShift::findOrFail($id);
        $this->editingId = $shift->id;
        $this->shiftName = $shift->name;
        $this->shiftStart = $shift->start_time;
        $this->shiftEnd = $shift->end_time;
        $this->shiftDays = array_map('strval', $shift->days ?? []);
        $this->shiftActive = $shift->is_active;
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'shiftName', 'shiftDays');
        $this->shiftStart = '08:00';
        $this->shiftEnd = '14:00';
        $this->shiftActive = true;
        $this->resetErrorBag();
    }

    public function saveShift(): void
    {
        $this->authorizeAdmin();

        $this->validate([
            'shiftName' => ['required', 'string', 'max:60'],
            'shiftStart' => ['required', function ($a, $v, $fail) {
                if (! S::isValidTime((string) $v)) {
                    $fail('Use a time like 08:00.');
                }
            }],
            'shiftEnd' => ['required', function ($a, $v, $fail) {
                if (! S::isValidTime((string) $v)) {
                    $fail('Use a time like 14:00.');
                }
                if ((string) $v === $this->shiftStart) {
                    $fail('A shift cannot start and end at the same time.');
                }
            }],
            'shiftDays' => ['array'],
            'shiftDays.*' => ['integer', 'between:0,6'],
        ], [], ['shiftName' => 'shift name', 'shiftStart' => 'start time', 'shiftEnd' => 'end time']);

        $days = collect($this->shiftDays)->map(fn ($d) => (int) $d)->unique()->sort()->values()->all();

        $attributes = [
            'name' => trim($this->shiftName),
            'start_time' => $this->shiftStart,
            'end_time' => $this->shiftEnd,
            'days' => $days === [] || count($days) === 7 ? null : $days,
            'is_active' => $this->shiftActive,
        ];

        if ($this->editingId) {
            $shift = ProviderShift::findOrFail($this->editingId);
            $shift->update($attributes);
            $verb = 'updated';
        } else {
            $shift = ProviderShift::create($attributes + ['sort_order' => (int) ProviderShift::max('sort_order') + 1]);
            $verb = 'created';
        }

        ActivityLogger::log(auth()->user(), 'provider_shift', $shift->id, "Provider shift {$verb}: {$shift->name}", $attributes);

        $this->cancelEdit();
        $this->flashMessage = "Shift {$verb}.";
    }

    public function deleteShift(int $id): void
    {
        $this->authorizeAdmin();

        $shift = ProviderShift::findOrFail($id);
        ActivityLogger::log(auth()->user(), 'provider_shift', $shift->id, "Provider shift deleted: {$shift->name}", ['providers' => $shift->providers()->count()]);
        $shift->delete();

        if ($this->editingId === $id) {
            $this->cancelEdit();
        }
        $this->flashMessage = 'Shift deleted.';
    }

    private function authorizeAdmin(): void
    {
        abort_unless(SuperAdminGate::allows(auth()->user()), 403, 'Only a Super Admin can change provider availability and shifts.');
    }

    public function render()
    {
        return view('livewire.providers.availability', [
            'shifts' => ProviderShift::query()->withCount('providers')->orderBy('sort_order')->orderBy('id')->get(),
            'dayNames' => ProviderShift::DAY_NAMES,
        ])->layout('layouts.admin', ['title' => 'Availability & shifts']);
    }
}
