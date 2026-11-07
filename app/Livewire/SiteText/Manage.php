<?php

namespace App\Livewire\SiteText;

use App\Services\SettingsAuditor;
use App\Support\Languages;
use App\Support\SiteText;
use App\Support\SuperAdminGate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Admin → Website & Pages → Website text & language (owner rule 2026-10-31: every word a customer reads is the Super
 * Admin's). Super Admin only, checked on mount and on every action; every change goes through SettingsAuditor
 * (audit-logged). A blank box means "use the default" shown under it.
 *
 * Owner 2026-11-07: any number of languages (Admin → Languages). The screen shows English and ONE other language at a
 * time (the picker), and that language's words can be downloaded as a spreadsheet, filled in by a translator and
 * uploaded back. An upload only fills the boxes; nothing changes on the website until Save.
 */
class Manage extends Component
{
    use WithFileUploads;

    /** The language shown next to English. */
    #[Url(as: 'language')]
    public string $language = '';

    public string $group = 'language';
    public string $search = '';

    /** @var array<string, array<string, string>> keyed by the text key with dots swapped for "__", then language code */
    public array $values = [];

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $sheet = null;

    public string $flashMessage = '';

    /** @var list<string> */
    public array $sheetWarnings = [];

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->language = $this->pickLanguage($this->language);
        $this->load();
    }

    private function pickLanguage(string $wanted): string
    {
        $codes = array_values(array_diff(Languages::codes(), ['en']));

        return in_array($wanted, $codes, true) ? $wanted : ($codes[0] ?? 'te');
    }

    private function load(): void
    {
        $codes = Languages::codes();
        foreach (array_keys(SiteText::TEXTS) as $key) {
            foreach ($codes as $code) {
                $this->values[self::field($key)][$code] = SiteText::saved($key, $code) ?? '';
            }
        }
    }

    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function updatedLanguage(): void
    {
        $this->language = $this->pickLanguage($this->language);
        $this->sheetWarnings = [];
    }

    public function save(): void
    {
        $this->authorizeAdmin();
        $this->validate(['values.*.*' => ['nullable', 'string', 'max:500']]);

        $admin = auth()->user();
        $changed = 0;
        foreach (array_keys(SiteText::TEXTS) as $key) {
            foreach (Languages::codes() as $locale) {
                $value = trim((string) ($this->values[self::field($key)][$locale] ?? ''));
                $changed += (int) SettingsAuditor::put($admin, SiteText::settingKey($key, $locale), $value === '' ? null : $value);
            }
        }

        SiteText::forget();
        $this->load();
        $this->sheetWarnings = [];
        $this->flashMessage = $changed > 0 ? "Saved {$changed} change(s). The website shows them now." : 'Nothing changed.';
    }

    /**
     * The spreadsheet for the chosen language: key, what it is, English (to translate from), the words now (blank =
     * not typed yet). CSV with a BOM, so Excel and Google Sheets show every script correctly.
     */
    public function download()
    {
        $this->authorizeAdmin();
        $code = $this->language;
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['key', 'what it is', 'English', Languages::englishName($code)." ({$code})"]);
        foreach (SiteText::TEXTS as $key => $entry) {
            $now = SiteText::saved($key, $code) ?? SiteText::defaultFor($key, $code);
            fputcsv($out, [$key, $entry[1], SiteText::saved($key, 'en') ?? $entry[2], $now]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response()->streamDownload(fn () => print ($csv), "website-text-{$code}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Upload: fills the chosen language's boxes from the sheet (4th column). Save to make it live. */
    public function updatedSheet(): void
    {
        $this->authorizeAdmin();
        $this->sheetWarnings = [];
        $this->validate(['sheet' => ['required', 'file', 'max:4096', 'mimes:csv,txt,xlsx,xls']], [], ['sheet' => 'spreadsheet']);

        $ext = strtolower((string) $this->sheet->getClientOriginalExtension());
        $type = match ($ext) {
            'xlsx' => \Maatwebsite\Excel\Excel::XLSX,
            'xls' => \Maatwebsite\Excel\Excel::XLS,
            default => \Maatwebsite\Excel\Excel::CSV,
        };
        $rows = Excel::toArray(new class {}, $this->sheet->getRealPath(), null, $type)[0] ?? [];
        $this->sheet = null;

        $header = array_map(fn ($c) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $c)), $rows[0] ?? []);
        $code = $this->language;
        if (($header[0] ?? '') !== 'key' || ! str_contains($header[3] ?? '', "({$code})")) {
            $this->addError('sheet', 'This is not the '.Languages::englishName($code)." sheet: download it from this screen with {$code} picked, fill in the 4th column, and upload it back.");

            return;
        }

        $filled = 0;
        foreach (array_slice($rows, 1) as $row) {
            $key = trim((string) ($row[0] ?? ''));
            $words = trim((string) ($row[3] ?? ''));
            if (! isset(SiteText::TEXTS[$key]) || $words === '') {
                continue;
            }
            if (mb_strlen($words) > 500) {
                $this->sheetWarnings[] = "{$key}: longer than 500 letters, not loaded.";

                continue;
            }
            preg_match_all('/:([a-z]+)/', SiteText::TEXTS[$key][2], $want);
            preg_match_all('/:([a-z]+)/', $words, $got);
            if (array_diff($want[1], $got[1]) !== []) {
                $this->sheetWarnings[] = "{$key}: keep ".implode(', ', array_map(fn ($v) => ':'.$v, array_diff($want[1], $got[1])))." as it is (the site puts a name or number there).";
            }
            // The built-in words of a language are its default: no need to save a copy.
            $this->values[self::field($key)][$code] = $words === SiteText::defaultFor($key, $code) ? '' : $words;
            $filled++;
        }

        $this->flashMessage = "Loaded {$filled} text(s) from the sheet. Check them, then press Save.";
    }

    private function authorizeAdmin(): void
    {
        abort_unless(SuperAdminGate::allows(auth()->user()), 403, 'Only a Super Admin can change the website text.');
    }

    public function render()
    {
        $code = $this->language;
        $needle = mb_strtolower(trim($this->search));
        $rows = collect(SiteText::TEXTS)
            ->filter(fn ($e, $key) => $needle !== ''
                ? str_contains(mb_strtolower($key.' '.$e[1].' '.$e[2].' '.$e[3].' '.($this->values[self::field($key)][$code] ?? '')), $needle)
                : $e[0] === $this->group)
            ->map(fn ($e, $key) => ['key' => $key, 'field' => self::field($key), 'group' => $e[0], 'label' => $e[1], 'en' => $e[2], 'other' => SiteText::defaultFor($key, $code)]);

        $missing = collect(SiteText::TEXTS)->keys()
            ->filter(fn ($key) => trim($this->values[self::field($key)][$code] ?? '') === '' && SiteText::defaultFor($key, $code) === '')
            ->count();

        return view('livewire.site-text.manage', [
            'groups' => SiteText::GROUPS,
            'rows' => $rows->values(),
            'counts' => collect(SiteText::TEXTS)->countBy(fn ($e) => $e[0]),
            'languages' => array_filter(Languages::all(), fn ($l) => $l['code'] !== 'en'),
            'otherName' => Languages::name($code),
            'otherEnglish' => Languages::englishName($code),
            'otherRtl' => Languages::isRtl($code),
            'otherOn' => Languages::isActive($code),
            'missing' => $missing,
            'total' => count(SiteText::TEXTS),
        ])->layout('layouts.admin', ['title' => 'Website text & language']);
    }
}
