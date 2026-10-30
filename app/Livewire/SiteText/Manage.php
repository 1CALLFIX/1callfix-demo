<?php

namespace App\Livewire\SiteText;

use App\Services\SettingsAuditor;
use App\Support\SiteText;
use App\Support\SuperAdminGate;
use Livewire\Component;

/**
 * Admin → Growth → Website text & language (owner rule 2026-10-31: every word a customer reads, in English and
 * Telugu, is the Super Admin's). Super Admin only, checked on mount and on save; every change goes through
 * SettingsAuditor (audit-logged). A blank box means "use the default" shown under it.
 */
class Manage extends Component
{
    public bool $teluguEnabled = false;
    public string $defaultLocale = 'en';
    public string $group = 'language';
    public string $search = '';

    /** @var array<string, array{en: string, te: string}> keyed by the text key with dots swapped for "__" */
    public array $values = [];

    public string $flashMessage = '';

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->load();
    }

    private function load(): void
    {
        $this->teluguEnabled = SiteText::teluguEnabled();
        $this->defaultLocale = (string) (\App\Models\Setting::get(SiteText::DEFAULT_LOCALE, null) ?: 'en');
        foreach (array_keys(SiteText::TEXTS) as $key) {
            $this->values[self::field($key)] = ['en' => SiteText::saved($key, 'en') ?? '', 'te' => SiteText::saved($key, 'te') ?? ''];
        }
    }

    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function save(): void
    {
        $this->authorizeAdmin();
        $this->validate([
            'defaultLocale' => ['required', 'in:'.implode(',', SiteText::LOCALES)],
            'values.*.en' => ['nullable', 'string', 'max:500'],
            'values.*.te' => ['nullable', 'string', 'max:500'],
        ]);

        $admin = auth()->user();
        $changed = 0;
        $changed += (int) SettingsAuditor::put($admin, SiteText::TELUGU_ENABLED, $this->teluguEnabled ? '1' : '0');
        $changed += (int) SettingsAuditor::put($admin, SiteText::DEFAULT_LOCALE, $this->defaultLocale === 'en' ? null : $this->defaultLocale);
        foreach (array_keys(SiteText::TEXTS) as $key) {
            foreach (SiteText::LOCALES as $locale) {
                $value = trim((string) ($this->values[self::field($key)][$locale] ?? ''));
                $changed += (int) SettingsAuditor::put($admin, SiteText::settingKey($key, $locale), $value === '' ? null : $value);
            }
        }

        SiteText::forget();
        $this->load();
        $this->flashMessage = $changed > 0 ? "Saved {$changed} change(s). The website shows them now." : 'Nothing changed.';
    }

    private function authorizeAdmin(): void
    {
        abort_unless(SuperAdminGate::allows(auth()->user()), 403, 'Only a Super Admin can change the website text.');
    }

    public function render()
    {
        $needle = mb_strtolower(trim($this->search));
        $rows = collect(SiteText::TEXTS)
            ->filter(fn ($e, $key) => $needle !== ''
                ? str_contains(mb_strtolower($key.' '.$e[1].' '.$e[2].' '.$e[3]), $needle)
                : $e[0] === $this->group)
            ->map(fn ($e, $key) => ['key' => $key, 'field' => self::field($key), 'group' => $e[0], 'label' => $e[1], 'en' => $e[2], 'te' => $e[3]]);

        return view('livewire.site-text.manage', [
            'groups' => SiteText::GROUPS,
            'rows' => $rows->values(),
            'counts' => collect(SiteText::TEXTS)->countBy(fn ($e) => $e[0]),
        ])->layout('layouts.admin', ['title' => 'Website text & language']);
    }
}
