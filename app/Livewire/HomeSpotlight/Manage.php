<?php

namespace App\Livewire\HomeSpotlight;

use App\Models\HomeSpotlight;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;

// REF 1CF-HOME-SPOTLIGHT-001 — curates the home page collage: up to six numbered
// slots, each a service or a whole category, with an optional badge ("New",
// "Featured"…). Empty table => the home page keeps its automatic collage.
// Reuses banners.manage (same "what the home page promotes" audience) so no
// new permission needs seeding.
class Manage extends Component
{
    /** @var array<int, array{type: string, target: string, badge: string, active: bool}> keyed 1..SLOTS */
    public array $spots = [];

    public string $notice = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('banners.manage'), 403, 'You do not have permission to manage the home spotlight.');

        $rows = HomeSpotlight::query()->get()->keyBy('position');

        for ($i = 1; $i <= HomeSpotlight::SLOTS; $i++) {
            $row = $rows->get($i);
            $this->spots[$i] = [
                'type' => $row?->target_type ?? 'service',
                'target' => $row ? (string) $row->target_id : '',
                'badge' => $row?->badge ?? '',
                'active' => $row?->is_active ?? true,
            ];
        }
    }

    public function clearSlot(int $position): void
    {
        if (isset($this->spots[$position])) {
            $this->spots[$position] = ['type' => 'service', 'target' => '', 'badge' => '', 'active' => true];
        }
    }

    public function updatedSpots($value, $key): void
    {
        // Switching a slot between Service and Category invalidates its target.
        if (Str::endsWith($key, '.type')) {
            $this->spots[(int) Str::before($key, '.')]['target'] = '';
        }
    }

    public function save(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('banners.manage'), 403);

        $rules = [];
        foreach (range(1, HomeSpotlight::SLOTS) as $i) {
            $rules["spots.$i.type"] = ['required', 'in:service,category'];
            $rules["spots.$i.badge"] = ['nullable', 'string', 'max:24'];
        }
        $this->validate($rules);

        DB::transaction(function () {
            foreach ($this->spots as $position => $slot) {
                $targetId = (int) $slot['target'];

                if ($targetId <= 0 || ! $this->targetExists($slot['type'], $targetId)) {
                    HomeSpotlight::where('position', $position)->delete();

                    continue;
                }

                HomeSpotlight::updateOrCreate(
                    ['position' => $position],
                    [
                        'target_type' => $slot['type'],
                        'target_id' => $targetId,
                        'badge' => trim($slot['badge']) !== '' ? trim($slot['badge']) : null,
                        'is_active' => (bool) $slot['active'],
                    ],
                );
            }
        });

        $this->notice = 'Home spotlight saved. The home page shows it right away.';
    }

    private function targetExists(string $type, int $id): bool
    {
        return $type === 'category'
            ? ServiceCategory::whereKey($id)->exists()
            : Service::whereKey($id)->exists();
    }

    public function render()
    {
        return view('livewire.home-spotlight.manage', [
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'categories' => ServiceCategory::query()->orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.admin', ['title' => 'Home Spotlight']);
    }
}
