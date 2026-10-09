<?php

namespace App\Livewire\Provider;

use App\Livewire\Provider\Concerns\InteractsWithProvider;
use App\Models\ProviderShift;
use App\Services\Providers\ShiftSchedule;
use Livewire\Component;

/**
 * "My shifts": the provider ticks the admin-defined slots they will work (Swiggy-style). What those choices mean
 * (nothing / a reminder / only-online-inside-shifts) is the admin's mode setting; this page only records the choice.
 */
class Shifts extends Component
{
    use InteractsWithProvider;

    public string $notice = '';

    public function toggle(int $shiftId): void
    {
        $provider = $this->provider();
        $shift = ProviderShift::where('is_active', true)->findOrFail($shiftId);

        $provider->shifts()->toggle([$shift->id]);

        $this->notice = $provider->shifts()->whereKey($shift->id)->exists()
            ? "Added: {$shift->name}."
            : "Removed: {$shift->name}.";
    }

    public function render(ShiftSchedule $schedule)
    {
        $provider = $this->provider()->loadMissing('franchise.country');

        return view('livewire.provider.shifts', [
            'shifts' => ProviderShift::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(),
            'chosenIds' => $provider->shifts()->pluck('provider_shifts.id')->all(),
            'mode' => ShiftSchedule::mode(),
            'current' => $schedule->current($provider),
            'next' => $schedule->next($provider),
            'franchise' => $provider->franchise,
        ])->layout('components.layouts.provider', ['title' => 'My shifts']);
    }
}
