<?php

namespace App\Livewire\HomeSections;

use App\Models\Setting;
use App\Services\SettingsAuditor;
use App\Support\HomeDoors;
use App\Support\SuperAdminGate;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Admin → Growth → Homepage sections (owner rule 2026-10-31: every small thing is the Super Admin's). Starts with the
 * "Explore 1CallFix" doors: the row on/off and where, live numbers on/off, the line under the heading, and per door
 * on/off, order and picture. Words are on Website text & language. Super Admin only; every change is audit-logged.
 * Pictures: PNG/JPEG/WebP only (no SVG upload, it can carry script), public disk.
 */
class Manage extends Component
{
    use WithFileUploads;

    public bool $show = true;
    public string $position = 'top';
    public bool $liveInfo = true;
    public bool $showPromise = true;

    /** @var array<string, bool> */
    public array $doorShow = [];
    /** @var array<string, string> */
    public array $doorOrder = [];
    /** @var array<string, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null> */
    public array $doorImage = [];

    public string $flashMessage = '';

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->load();
    }

    private function load(): void
    {
        $this->show = HomeDoors::show();
        $this->position = HomeDoors::position();
        $this->liveInfo = HomeDoors::liveInfo();
        $this->showPromise = HomeDoors::showPromise();
        foreach (array_keys(HomeDoors::DOORS) as $door) {
            $this->doorShow[$door] = HomeDoors::doorShown($door);
            $this->doorOrder[$door] = (string) HomeDoors::doorOrder($door);
            $this->doorImage[$door] = null;
        }
    }

    public function save(): void
    {
        $this->authorizeAdmin();
        $this->validate([
            'position' => ['required', 'in:'.implode(',', HomeDoors::POSITIONS)],
            'doorOrder.*' => ['required', 'integer', 'between:1,99'],
            'doorImage.*' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ], [], ['doorOrder.*' => 'order', 'doorImage.*' => 'picture']);

        $bool = fn (bool $v) => $v ? '1' : '0';
        $admin = auth()->user();
        $changed = false;
        $values = [
            HomeDoors::SHOW => $bool($this->show),
            HomeDoors::POSITION => $this->position,
            HomeDoors::LIVE_INFO => $bool($this->liveInfo),
            HomeDoors::SHOW_PROMISE => $bool($this->showPromise),
        ];
        foreach (array_keys(HomeDoors::DOORS) as $door) {
            $values["home.door.{$door}.show"] = $bool((bool) ($this->doorShow[$door] ?? true));
            $values["home.door.{$door}.order"] = (string) (int) ($this->doorOrder[$door] ?? HomeDoors::DOORS[$door][0]);
        }
        foreach ($values as $key => $value) {
            $changed = SettingsAuditor::put($admin, $key, $value) || $changed;
        }

        foreach (array_keys(HomeDoors::DOORS) as $door) {
            if ($file = $this->doorImage[$door] ?? null) {
                $this->replacePicture($door, $file->store('site/home', 'public'));
                $changed = true;
            }
        }

        HomeDoors::forget();
        $this->load();
        $this->flashMessage = $changed ? 'Saved. The homepage shows it now.' : 'Nothing changed.';
    }

    /** Back to the built-in picture for one door. */
    public function resetPicture(string $door): void
    {
        $this->authorizeAdmin();
        abort_unless(array_key_exists($door, HomeDoors::DOORS), 404);
        if (HomeDoors::hasUpload($door)) {
            $this->replacePicture($door, '');
            HomeDoors::forget();
            $this->flashMessage = 'Saved. The built-in picture is back.';
        }
    }

    private function replacePicture(string $door, string $path): void
    {
        $key = HomeDoors::imageKey($door);
        $old = trim((string) Setting::get($key, ''));
        SettingsAuditor::put(auth()->user(), $key, $path);
        if ($old !== '' && $old !== $path) {
            Storage::disk('public')->delete($old);
        }
    }

    private function authorizeAdmin(): void
    {
        abort_unless(SuperAdminGate::allows(auth()->user()), 403, 'Only a Super Admin can change the homepage sections.');
    }

    public function render()
    {
        $doors = collect(array_keys(HomeDoors::DOORS))->mapWithKeys(fn ($door) => [$door => [
            'title' => \App\Support\SiteText::get("home.door.{$door}.title"),
            'image' => HomeDoors::imageUrl($door),
            'uploaded' => HomeDoors::hasUpload($door),
        ]]);

        return view('livewire.home-sections.manage', ['doors' => $doors])
            ->layout('layouts.admin', ['title' => 'Homepage sections']);
    }
}
