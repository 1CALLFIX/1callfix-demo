<?php

namespace App\Livewire\Seo;

use App\Models\Setting;
use App\Services\Seo\SeoSettings;
use App\Services\SettingsAuditor;
use App\Support\Seo;
use App\Support\SuperAdminGate;
use Livewire\Component;

/**
 * Admin → SEO → Search & social. One screen for every global SEO value (site title template, default description
 * and share image, home page, per-module copy, business details for structured data, search-console verification
 * tags, and the opt-in host redirect). Super Admin only — checked here, so it holds for a direct Livewire call —
 * and every changed key goes through SettingsAuditor (audit-logged). A blank field clears the setting back to the
 * built-in fallback, so a page can never be left without a title or description.
 */
class Settings extends Component
{
    public string $titleTemplate = '';
    public string $defaultDescription = '';
    public string $defaultImageUrl = '';
    public string $homeTitle = '';
    public string $homeDescription = '';
    public string $verifyGoogle = '';
    public string $verifyBing = '';
    public bool $redirectHost = false;

    /** @var array<string, string> */
    public array $business = [];
    /** @var array<string, string> module code => title */
    public array $moduleTitles = [];
    /** @var array<string, string> module code => description */
    public array $moduleDescriptions = [];

    public string $flashMessage = '';

    public function mount(): void
    {
        abort_unless(SuperAdminGate::allows(auth()->user()), 403, 'Only a Super Admin can change global SEO settings.');

        $this->titleTemplate = (string) Setting::get(SeoSettings::TITLE_TEMPLATE, '');
        $this->defaultDescription = (string) Setting::get(SeoSettings::DEFAULT_DESCRIPTION, '');
        $this->defaultImageUrl = (string) Setting::get(SeoSettings::DEFAULT_IMAGE, '');
        $this->homeTitle = (string) Setting::get(SeoSettings::HOME_TITLE, '');
        $this->homeDescription = (string) Setting::get(SeoSettings::HOME_DESCRIPTION, '');
        $this->verifyGoogle = (string) Setting::get(SeoSettings::VERIFY_GOOGLE, '');
        $this->verifyBing = (string) Setting::get(SeoSettings::VERIFY_BING, '');
        $this->redirectHost = SeoSettings::redirectHostEnabled();

        foreach (SeoSettings::BUSINESS_FIELDS as $field) {
            $this->business[$field] = (string) Setting::get("seo.business.{$field}", '');
        }

        $saved = SeoSettings::modules();
        foreach (SeoSettings::moduleList() as $module) {
            $this->moduleTitles[$module->code] = (string) ($saved[$module->code]['title'] ?? '');
            $this->moduleDescriptions[$module->code] = (string) ($saved[$module->code]['description'] ?? '');
        }
    }

    public function save(): void
    {
        abort_unless(SuperAdminGate::allows(auth()->user()), 403, 'Only a Super Admin can change global SEO settings.');

        $token = ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-]+$/'];

        $this->validate([
            'titleTemplate' => ['nullable', 'string', 'max:120', function ($attribute, $value, $fail) {
                if (trim((string) $value) !== '' && ! str_contains($value, '{page}')) {
                    $fail('The title template must contain {page}.');
                }
            }],
            'defaultDescription' => ['nullable', 'string', 'max:320'],
            'defaultImageUrl' => ['nullable', 'string', 'max:500', 'regex:#^(https?://|/)[^\s]+$#i'],
            'homeTitle' => ['nullable', 'string', 'max:160'],
            'homeDescription' => ['nullable', 'string', 'max:320'],
            'verifyGoogle' => $token,
            'verifyBing' => $token,
            'business.legal_name' => ['nullable', 'string', 'max:120'],
            'business.phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 \-()]{7,20}$/'],
            'business.email' => ['nullable', 'email', 'max:120'],
            'business.street' => ['nullable', 'string', 'max:160'],
            'business.city' => ['nullable', 'string', 'max:80'],
            'business.region' => ['nullable', 'string', 'max:80'],
            'business.postal_code' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9 \-]+$/'],
            'business.country' => ['nullable', 'string', 'size:2', 'alpha'],
            'moduleTitles.*' => ['nullable', 'string', 'max:160'],
            'moduleDescriptions.*' => ['nullable', 'string', 'max:320'],
        ], [], [
            'titleTemplate' => 'title template', 'defaultDescription' => 'default description', 'defaultImageUrl' => 'default image URL',
            'verifyGoogle' => 'Google verification token', 'verifyBing' => 'Bing verification token',
            'business.phone' => 'phone', 'business.email' => 'email', 'business.postal_code' => 'postal code', 'business.country' => 'country code',
        ]);

        $admin = auth()->user();
        $changed = false;
        $put = function (string $key, $value) use ($admin, &$changed) {
            $changed = SettingsAuditor::put($admin, $key, $value) || $changed;
        };

        $put(SeoSettings::TITLE_TEMPLATE, trim($this->titleTemplate));
        $put(SeoSettings::DEFAULT_DESCRIPTION, trim($this->defaultDescription));
        $put(SeoSettings::DEFAULT_IMAGE, trim($this->defaultImageUrl));
        $put(SeoSettings::HOME_TITLE, trim($this->homeTitle));
        $put(SeoSettings::HOME_DESCRIPTION, trim($this->homeDescription));
        $put(SeoSettings::VERIFY_GOOGLE, trim($this->verifyGoogle));
        $put(SeoSettings::VERIFY_BING, trim($this->verifyBing));
        $put(SeoSettings::REDIRECT_HOST, $this->redirectHost ? '1' : '0');

        foreach (SeoSettings::BUSINESS_FIELDS as $field) {
            $value = trim((string) ($this->business[$field] ?? ''));
            $put("seo.business.{$field}", $field === 'country' ? strtoupper($value) : $value);
        }

        $modules = [];
        foreach ($this->moduleTitles + $this->moduleDescriptions as $code => $_) {
            $title = trim((string) ($this->moduleTitles[$code] ?? ''));
            $description = trim((string) ($this->moduleDescriptions[$code] ?? ''));
            if ($title !== '' || $description !== '') {
                $modules[$code] = ['title' => $title ?: null, 'description' => $description ?: null];
            }
        }
        ksort($modules);
        $put(SeoSettings::MODULES, $modules === [] ? null : json_encode($modules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($changed) {
            Seo::bumpSitemap();
        }

        $this->flashMessage = $changed ? 'SEO settings saved.' : 'Nothing changed.';
    }

    public function render()
    {
        return view('livewire.seo.settings', [
            'modules' => SeoSettings::moduleList(),
            'siteName' => SeoSettings::siteName(),
            'homePreviewTitle' => trim($this->homeTitle) !== '' ? trim($this->homeTitle) : 'Home services, on call',
            'homePreviewIsComplete' => trim($this->homeTitle) !== '',
            'template' => str_contains($this->titleTemplate, '{page}') ? $this->titleTemplate : SeoSettings::FALLBACK_TEMPLATE,
            'canonicalBase' => Seo::canonicalBase(),
        ])->layout('layouts.admin', ['title' => 'Search & social']);
    }
}
