<?php

namespace App\Livewire\InfoPages;

use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\SettingsAuditor;
use App\Support\InfoPages\FranchisePage as F;
use App\Support\Seo;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Admin → Website & Pages → Franchise page. Owner 2026-11-07: "fully controlled by Super Admin only" — this screen is
 * checked against the super_admin role itself on open and on save (not a grantable permission, so it can never be
 * handed to another role from the Roles screen). Every change is audit-logged (SettingsAuditor per key, ActivityLogger
 * per save).
 */
class FranchiseSettings extends Component
{
    use WithFileUploads;

    public const HERO_IMAGE = 'franchise_page.hero_image';

    /** @var array<string, mixed> */
    public array $c = [];

    public bool $enabled = true;
    public bool $accepting = true;
    public $heroImageFile = null;
    public string $flashMessage = '';

    public function mount(): void
    {
        $this->authorizeSuperAdmin();
        $this->c = F::content();
        $this->enabled = F::enabled();
        $this->accepting = (string) Setting::get(F::ACCEPTING, '1') !== '0';
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()?->role === 'super_admin', 403, 'Only the Super Admin can edit the Franchise page.');
    }

    public function addRow(string $list): void
    {
        $this->authorizeSuperAdmin();
        if (! isset(F::LISTS[$list])) {
            return;
        }
        [$_label, $max, $fields] = F::LISTS[$list];
        $rows = $this->c[$list] ?? [];
        if (count($rows) >= $max) {
            return;
        }
        $row = array_fill_keys(array_keys($fields), '');
        if (isset($row['icon'])) {
            $row['icon'] = F::ICONS[0];
        }
        $rows[] = $row;
        $this->c[$list] = $rows;
    }

    public function removeRow(string $list, int $i): void
    {
        $this->authorizeSuperAdmin();
        if (isset(F::LISTS[$list], $this->c[$list][$i])) {
            unset($this->c[$list][$i]);
            $this->c[$list] = array_values($this->c[$list]);
        }
    }

    public function moveUp(string $list, int $i): void
    {
        $this->authorizeSuperAdmin();
        if (isset(F::LISTS[$list], $this->c[$list][$i], $this->c[$list][$i - 1])) {
            [$this->c[$list][$i - 1], $this->c[$list][$i]] = [$this->c[$list][$i], $this->c[$list][$i - 1]];
        }
    }

    /** Back to the shipped wording (not saved until Save). */
    public function resetToDefaults(): void
    {
        $this->authorizeSuperAdmin();
        $this->c = F::defaults();
        $this->flashMessage = 'Default wording loaded. Press Save to publish it.';
    }

    public function removeHeroImage(): void
    {
        $this->authorizeSuperAdmin();
        if (SettingsAuditor::put(auth()->user(), self::HERO_IMAGE, null)) {
            ActivityLogger::log(auth()->user(), 'franchise_page', 0, 'Franchise page picture removed', []);
        }
        $this->flashMessage = 'Picture removed.';
    }

    public function save(): void
    {
        $this->authorizeSuperAdmin();
        $this->resetErrorBag();

        $this->validate(['heroImageFile' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096']], [], ['heroImageFile' => 'picture']);

        $errors = F::validate($this->c);
        if ($errors !== []) {
            foreach ($errors as $field => $message) {
                $this->addError('c.'.$field, $message);
            }
            $this->flashMessage = '';

            return;
        }

        $admin = auth()->user();
        $changed = [];
        $clean = F::clean($this->c);
        // Stored only when it differs from the shipped defaults, so a later wording improvement in code still reaches
        // a page the Super Admin never edited.
        $value = $clean == F::clean(F::defaults()) ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
        if (SettingsAuditor::put($admin, F::CONTENT, $value)) {
            $changed[] = 'content';
        }
        if (SettingsAuditor::put($admin, F::ENABLED, $this->enabled ? '1' : '0')) {
            $changed[] = 'enabled';
        }
        if (SettingsAuditor::put($admin, F::ACCEPTING, $this->accepting ? '1' : '0')) {
            $changed[] = 'accepting';
        }
        if ($this->heroImageFile) {
            $path = $this->heroImageFile->store('site/franchise', 'public');
            SettingsAuditor::put($admin, self::HERO_IMAGE, $path);
            $this->heroImageFile = null;
            $changed[] = 'hero_image';
        }

        if ($changed !== []) {
            ActivityLogger::log($admin, 'franchise_page', 0, 'Franchise page saved', ['keys' => $changed]);
            Seo::bumpSitemap();
        }
        $this->c = F::content();
        $this->flashMessage = $changed !== [] ? 'Franchise page saved. It is live now.' : 'Nothing changed.';
    }

    public static function heroImageUrl(): ?string
    {
        $path = (string) Setting::get(self::HERO_IMAGE, '');

        return $path !== '' ? Storage::disk('public')->url($path) : null;
    }

    public function render()
    {
        return view('livewire.info-pages.franchise-settings', [
            'heroUrl' => self::heroImageUrl(),
        ])->layout('layouts.admin', ['title' => 'Franchise page']);
    }
}
