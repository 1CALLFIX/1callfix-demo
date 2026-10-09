<?php

namespace App\Livewire\Security;

use App\Services\Security\SecurityHeaderSettings as S;
use App\Services\SettingsAuditor;
use App\Support\SuperAdminGate;
use Livewire\Component;

/**
 * Admin → System → Security headers. Every HTTP security response header is a setting here, audit-logged through
 * SettingsAuditor. Super Admin only — checked in mount() and save(), so it holds for a direct Livewire call.
 * Blank text fields clear back to the built-in default, so a typo can never leave the site without a working header.
 */
class Headers extends Component
{
    public bool $enabled = true;
    public bool $nosniff = true;
    public bool $referrerOn = true;
    public string $referrer = S::DEFAULT_REFERRER;
    public bool $frameOn = true;
    public string $frame = S::DEFAULT_FRAME;
    public bool $hsts = false;
    public string $hstsMaxAge = '31536000';
    public bool $hstsSubdomains = false;
    public bool $hstsPreload = false;
    public bool $permissionsOn = false;
    public string $permissions = '';
    public string $cspMode = 'off';
    public string $csp = '';

    public string $flashMessage = '';

    public function mount(): void
    {
        $this->authorizeAdmin();

        $this->enabled = S::enabled();
        $this->nosniff = S::nosniff();
        $this->referrerOn = S::referrerPolicy() !== null;
        $this->referrer = S::referrerPolicy() ?? S::DEFAULT_REFERRER;
        $this->frameOn = S::frameOptions() !== null;
        $this->frame = S::frameOptions() ?? S::DEFAULT_FRAME;
        $this->hsts = S::hsts() !== null;
        $this->hstsMaxAge = (string) (\App\Models\Setting::get(S::HSTS_MAX_AGE) ?: S::DEFAULT_HSTS_MAX_AGE);
        $this->hstsSubdomains = \App\Models\Setting::get(S::HSTS_SUBDOMAINS) === '1';
        $this->hstsPreload = \App\Models\Setting::get(S::HSTS_PRELOAD) === '1';
        $this->permissionsOn = S::permissionsPolicy() !== null;
        $this->permissions = (string) \App\Models\Setting::get(S::PERMISSIONS_VALUE, '');
        $this->cspMode = S::cspMode();
        $this->csp = (string) \App\Models\Setting::get(S::CSP_POLICY, '');
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $oneLine = function ($attribute, $value, $fail) {
            if (! S::isSafeValue((string) $value)) {
                $fail('Use one line of plain text only (no line breaks or special characters).');
            }
        };

        $this->validate([
            'referrer' => ['required', 'in:'.implode(',', S::REFERRER_OPTIONS)],
            'frame' => ['required', 'in:'.implode(',', S::FRAME_OPTIONS)],
            'hstsMaxAge' => ['required', 'integer', 'min:300', 'max:63072000'],
            'permissions' => ['nullable', 'string', 'max:600', $oneLine],
            'cspMode' => ['required', 'in:'.implode(',', S::CSP_MODES)],
            'csp' => ['nullable', 'string', 'max:4000', $oneLine],
        ], [], [
            'hstsMaxAge' => 'HSTS max-age', 'permissions' => 'Permissions-Policy', 'csp' => 'Content-Security-Policy', 'cspMode' => 'CSP mode',
        ]);

        $admin = auth()->user();
        $changed = false;
        $put = function (string $key, $value) use ($admin, &$changed) {
            $changed = SettingsAuditor::put($admin, $key, $value) || $changed;
        };
        $flag = fn (bool $b) => $b ? '1' : '0';

        $put(S::MASTER, $flag($this->enabled));
        $put(S::NOSNIFF, $flag($this->nosniff));
        $put(S::REFERRER_ON, $flag($this->referrerOn));
        $put(S::REFERRER, $this->referrer);
        $put(S::FRAME_ON, $flag($this->frameOn));
        $put(S::FRAME, $this->frame);
        $put(S::HSTS, $flag($this->hsts));
        $put(S::HSTS_MAX_AGE, (string) (int) $this->hstsMaxAge);
        $put(S::HSTS_SUBDOMAINS, $flag($this->hstsSubdomains));
        $put(S::HSTS_PRELOAD, $flag($this->hstsPreload));
        $put(S::PERMISSIONS, $flag($this->permissionsOn));
        $put(S::PERMISSIONS_VALUE, trim($this->permissions));
        $put(S::CSP_MODE, $this->cspMode);
        $put(S::CSP_POLICY, trim($this->csp));

        $this->flashMessage = $changed ? 'Security header settings saved.' : 'Nothing changed.';
    }

    /** Put the built-in starter policy into the box (not saved until the admin saves). */
    public function useDefaultCsp(): void
    {
        $this->authorizeAdmin();
        $this->csp = S::DEFAULT_CSP;
    }

    public function useDefaultPermissions(): void
    {
        $this->authorizeAdmin();
        $this->permissions = S::DEFAULT_PERMISSIONS;
    }

    private function authorizeAdmin(): void
    {
        abort_unless(SuperAdminGate::allows(auth()->user()), 403, 'Only a Super Admin can change security headers.');
    }

    public function render()
    {
        return view('livewire.security.headers', [
            'referrerOptions' => S::REFERRER_OPTIONS,
            'frameOptions' => S::FRAME_OPTIONS,
            'defaultCsp' => S::DEFAULT_CSP,
            'defaultPermissions' => S::DEFAULT_PERMISSIONS,
        ])->layout('layouts.admin', ['title' => 'Security headers']);
    }
}
