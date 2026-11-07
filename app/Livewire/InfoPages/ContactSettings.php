<?php

namespace App\Livewire\InfoPages;

use App\Services\ActivityLogger;
use App\Services\SettingsAuditor;
use App\Support\InfoPages\ContactDetails as C;
use App\Support\Seo;
use Livewire\Component;

/**
 * Admin → Website & Pages → Contact page (owner 2026-11-07). The company's phone, WhatsApp, email, hours and address
 * for /contact (and the Franchise page's "Talk to us"). Super Admin only, checked against the role itself; every change
 * audit-logged. A blank field is simply not shown on the page. The page's words: Website text → "Contact page".
 */
class ContactSettings extends Component
{
    /** @var array<string, string> */
    public array $v = [];

    public bool $enabled = true;
    public string $flashMessage = '';

    public const ENABLED = 'contact.page_enabled';

    public function mount(): void
    {
        $this->authorizeSuperAdmin();
        foreach (array_keys(C::FIELDS) as $field) {
            $this->v[$field] = (string) C::get($field);
        }
        $this->enabled = self::pageEnabled();
    }

    public static function pageEnabled(): bool
    {
        return (string) \App\Models\Setting::get(self::ENABLED, '1') !== '0';
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()?->role === 'super_admin', 403, 'Only the Super Admin can edit the Contact page.');
    }

    public function save(): void
    {
        $this->authorizeSuperAdmin();
        $this->resetErrorBag();

        $values = array_map(fn ($x) => trim((string) $x), $this->v);
        $errors = C::validate($values);
        if ($errors !== []) {
            foreach ($errors as $field => $message) {
                $this->addError('v.'.$field, $message);
            }
            $this->flashMessage = '';

            return;
        }

        $admin = auth()->user();
        $changed = [];
        foreach (array_keys(C::FIELDS) as $field) {
            if (SettingsAuditor::put($admin, C::key($field), $values[$field] ?? null)) {
                $changed[] = $field;
            }
        }
        if (SettingsAuditor::put($admin, self::ENABLED, $this->enabled ? '1' : '0')) {
            $changed[] = 'enabled';
        }
        if ($changed !== []) {
            ActivityLogger::log($admin, 'contact_page', 0, 'Contact page saved', ['keys' => $changed]);
            Seo::bumpSitemap();
        }
        $this->flashMessage = $changed !== [] ? 'Contact page saved. It is live now.' : 'Nothing changed.';
    }

    public function render()
    {
        return view('livewire.info-pages.contact-settings')->layout('layouts.admin', ['title' => 'Contact page']);
    }
}
