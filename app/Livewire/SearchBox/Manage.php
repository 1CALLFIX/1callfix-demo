<?php

namespace App\Livewire\SearchBox;

use App\Models\Service;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Support\Modules;
use App\Support\SearchBoxSettings;
use Livewire\Component;

// REF 1CF-SEARCHBOX-ADMIN-001 — admin control for the header search box: which services the
// placeholder rotates through (and in what order), text size, text colour, the lead-in
// phrase and the rotation speed. Same audience as Banners / Home Spotlight (banners.manage).
class Manage extends Component
{
    /** @var array<int, string> slot 1..SLOTS => service id ('' = empty) */
    public array $picks = [];

    public string $size = 'medium';

    public string $color = '';

    public string $prefix = 'Search for';

    public int|string $seconds = 3;

    public string $notice = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('banners.manage'), 403, 'You do not have permission to manage the search box.');

        $ids = SearchBoxSettings::serviceIds();
        for ($i = 1; $i <= SearchBoxSettings::SLOTS; $i++) {
            $this->picks[$i] = isset($ids[$i - 1]) ? (string) $ids[$i - 1] : '';
        }

        $this->size = SearchBoxSettings::size();
        $this->color = SearchBoxSettings::color() ?? '';
        $this->prefix = SearchBoxSettings::prefix();
        $this->seconds = SearchBoxSettings::rotationSeconds();
    }

    public function resetColor(): void
    {
        $this->color = '';
    }

    public function clearPick(int $slot): void
    {
        if (isset($this->picks[$slot])) {
            $this->picks[$slot] = '';
        }
    }

    public function save(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('banners.manage'), 403);

        $this->validate([
            'size' => ['required', 'in:'.implode(',', array_keys(SearchBoxSettings::SIZES))],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'prefix' => ['required', 'string', 'max:40'],
            'seconds' => ['required', 'integer', 'min:2', 'max:10'],
        ], [
            'color.regex' => 'Use a colour like #1e40af (six hex digits), or press Reset for the default.',
        ]);

        $ids = collect($this->picks)->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0)->unique()->values();
        // Only real, active services can be rotated.
        $valid = Service::query()->whereIn('id', $ids)->where('is_active', true)->pluck('id')->all();
        $ids = $ids->filter(fn ($id) => in_array($id, $valid, true))->values();

        $before = [
            'services' => SearchBoxSettings::serviceIds(), 'size' => SearchBoxSettings::size(),
            'color' => SearchBoxSettings::color(), 'prefix' => SearchBoxSettings::prefix(), 'seconds' => SearchBoxSettings::rotationSeconds(),
        ];

        Setting::set(SearchBoxSettings::K_SERVICES, $ids->implode(','));
        Setting::set(SearchBoxSettings::K_SIZE, $this->size);
        Setting::set(SearchBoxSettings::K_COLOR, $this->color !== '' ? strtolower($this->color) : '');
        Setting::set(SearchBoxSettings::K_PREFIX, trim($this->prefix));
        Setting::set(SearchBoxSettings::K_SECONDS, (string) (int) $this->seconds);

        ActivityLogger::log(auth()->user(), 'search_box', 0, 'search box settings changed', [
            'before' => $before,
            'after' => ['services' => $ids->all(), 'size' => $this->size, 'color' => $this->color ?: null, 'prefix' => trim($this->prefix), 'seconds' => (int) $this->seconds],
        ]);

        $this->notice = 'Search box saved. The website shows it right away.';
    }

    public function render()
    {
        return view('livewire.search-box.manage', [
            'services' => Service::query()->where('is_active', true)
                ->whereHas('category', fn ($q) => $q->where('module', Modules::SERVICE)->where('is_active', true))
                ->orderBy('name')->get(['id', 'name']),
            'sizes' => SearchBoxSettings::SIZES,
            'previewService' => Service::query()->whereIn('id', array_filter(array_map('intval', $this->picks)))->value('name') ?? 'Split AC Jet Service',
        ])->layout('layouts.admin', ['title' => 'Search Box']);
    }
}
