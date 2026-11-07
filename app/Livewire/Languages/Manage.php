<?php

namespace App\Livewire\Languages;

use App\Models\Setting;
use App\Services\SettingsAuditor;
use App\Support\Languages;
use App\Support\SiteText;
use App\Support\SuperAdminGate;
use Livewire\Component;

/**
 * Admin → Website & Pages → Languages (owner 2026-11-07: "is it just on/off or again coding" — on/off). Super Admin
 * only, checked on mount and every action; every change goes through SettingsAuditor (audit-logged).
 *
 * English is always on. Telugu ships with its words. A new language starts OFF: type its words in Website text (or
 * upload the spreadsheet there), then switch it on. Words not typed yet show in English.
 */
class Manage extends Component
{
    /** @var array<string, array{name: string, english: string, rtl: bool, on: bool}> */
    public array $rows = [];

    public string $defaultLocale = 'en';

    public string $newCode = '';
    public string $newName = '';
    public string $newEnglish = '';
    public bool $newRtl = false;

    public string $flashMessage = '';

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->load();
    }

    private function load(): void
    {
        Languages::forget();
        $this->rows = [];
        foreach (Languages::all() as $code => $l) {
            $this->rows[$code] = ['name' => $l['name'], 'english' => $l['english'], 'rtl' => $l['rtl'], 'on' => $l['on']];
        }
        $default = (string) (Setting::get(SiteText::DEFAULT_LOCALE, null) ?: 'en');
        $this->defaultLocale = isset($this->rows[$default]) ? $default : 'en';
    }

    public function save(): void
    {
        $this->authorizeAdmin();
        $this->validate([
            'rows.*.name' => ['required', 'string', 'max:40'],
            'rows.*.english' => ['required', 'string', 'max:40'],
            'defaultLocale' => ['required', 'in:'.implode(',', array_keys($this->rows))],
        ], [], ['rows.*.name' => 'name', 'rows.*.english' => 'English name', 'defaultLocale' => 'first-time language']);

        if (! ($this->rows[$this->defaultLocale]['on'] ?? false) && $this->defaultLocale !== 'en') {
            $this->addError('defaultLocale', 'Switch that language on first, or pick another one.');

            return;
        }

        $changed = $this->store($this->listFromRows());
        $changed += (int) SettingsAuditor::put(auth()->user(), SiteText::DEFAULT_LOCALE, $this->defaultLocale === 'en' ? null : $this->defaultLocale);

        $this->load();
        $this->flashMessage = $changed > 0 ? 'Saved. The website shows it now.' : 'Nothing changed.';
    }

    public function add(): void
    {
        $this->authorizeAdmin();
        $this->newCode = strtolower(trim($this->newCode));
        $this->validate([
            'newCode' => ['required', 'string', function ($attr, $value, $fail) {
                if (! Languages::validCode($value)) {
                    $fail('Use the 2 or 3 letter language code, e.g. ta (Tamil), ur (Urdu), ar (Arabic), hi (Hindi).');
                } elseif (isset($this->rows[$value])) {
                    $fail('That language is already in the list.');
                }
            }],
            'newName' => ['required', 'string', 'max:40'],
            'newEnglish' => ['required', 'string', 'max:40'],
        ], [], ['newCode' => 'code', 'newName' => 'name in its own script', 'newEnglish' => 'English name']);

        $list = $this->listFromRows(fromSaved: true);
        $list[$this->newCode] = ['code' => $this->newCode, 'name' => trim($this->newName), 'english' => trim($this->newEnglish), 'rtl' => $this->newRtl, 'on' => false];
        $this->store($list);

        $added = trim($this->newEnglish);
        $this->reset('newCode', 'newName', 'newEnglish', 'newRtl');
        $this->load();
        $this->flashMessage = "Added {$added}. It is off: type its words in Website text first, then switch it on here.";
    }

    /** Only a language you added, and only while it is off (its typed words are kept, in case it comes back). */
    public function remove(string $code): void
    {
        $this->authorizeAdmin();
        $all = Languages::all();
        abort_unless(isset($all[$code]) && ! $all[$code]['built_in'], 404);
        if ($all[$code]['on']) {
            $this->flashMessage = 'Switch it off and save first.';

            return;
        }
        unset($all[$code]);
        $this->store($all);
        if (Setting::get(SiteText::DEFAULT_LOCALE) === $code) {
            SettingsAuditor::put(auth()->user(), SiteText::DEFAULT_LOCALE, null);
        }
        $this->load();
        $this->flashMessage = 'Removed.';
    }

    /** @return array<string, array{code: string, name: string, english: string, rtl: bool, on: bool}> */
    private function listFromRows(bool $fromSaved = false): array
    {
        $list = [];
        foreach (Languages::all() as $code => $l) {
            $row = $fromSaved ? $l : array_merge($l, $this->rows[$code] ?? []);
            if ($l['built_in']) { // built-in names and directions are fixed; only on/off
                $row['name'] = $l['name'];
                $row['english'] = $l['english'];
                $row['rtl'] = $l['rtl'];
            }
            $row['on'] = $code === 'en' || (bool) $row['on'];
            $list[$code] = ['code' => $code] + $row;
        }

        return $list;
    }

    private function store(array $list): int
    {
        $changed = (int) SettingsAuditor::put(auth()->user(), Languages::SETTING, Languages::encode($list));
        Languages::forget();
        SiteText::forget();

        return $changed;
    }

    private function authorizeAdmin(): void
    {
        abort_unless(SuperAdminGate::allows(auth()->user()), 403, 'Only a Super Admin can change the website languages.');
    }

    public function render()
    {
        $total = count(SiteText::TEXTS);
        $typed = [];
        foreach (array_keys($this->rows) as $code) {
            $typed[$code] = match ($code) {
                'en', 'te' => $total, // ship with all their words
                default => Setting::query()->where('scope_type', 'global')->where('key', 'like', 'text.%.'.$code)
                    ->whereNotNull('value')->where('value', '!=', '')->count(),
            };
        }

        return view('livewire.languages.manage', [
            'all' => Languages::all(),
            'typed' => $typed,
            'total' => $total,
        ])->layout('layouts.admin', ['title' => 'Languages']);
    }
}
