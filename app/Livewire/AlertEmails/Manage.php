<?php

namespace App\Livewire\AlertEmails;

use App\Services\AdminOpsAlertService;
use App\Services\SettingsAuditor;
use App\Support\SuperAdminGate;
use Livewire\Component;

/**
 * Super Admin on/off switch, per critical alert type, for the admin email
 * copy of that alert (alerts.email.<type>). Unset = ON for these five.
 * Email never depends on push being enabled or on an fcm_token. Every
 * change is audit-logged through SettingsAuditor. Warns plainly when the
 * mail driver cannot actually deliver (MAIL_MAILER=log/array).
 */
class Manage extends Component
{
    /** @var array<string, bool> alert type => email on */
    public array $enabled = [];

    public string $flashType = 'success';

    public string $flashMessage = '';

    public function mount(): void
    {
        SuperAdminGate::authorize(auth()->user());

        $service = app(AdminOpsAlertService::class);

        foreach (array_keys(AdminOpsAlertService::EMAIL_TYPES) as $type) {
            $this->enabled[$type] = $service->emailEnabled($type);
        }
    }

    public function save(): void
    {
        SuperAdminGate::authorize(auth()->user());

        $changed = false;

        foreach (array_keys(AdminOpsAlertService::EMAIL_TYPES) as $type) {
            $changed = SettingsAuditor::put(
                auth()->user(),
                AdminOpsAlertService::emailSettingKey($type),
                ! empty($this->enabled[$type]) ? '1' : '0',
            ) || $changed;
        }

        $this->flashType = 'success';
        $this->flashMessage = $changed ? 'Alert email settings saved.' : 'Nothing changed.';
    }

    public function render()
    {
        return view('livewire.alert-emails.manage', [
            'types' => AdminOpsAlertService::EMAIL_TYPES,
            'mailDriver' => (string) config('mail.default'),
            'mailCanDeliver' => ! in_array(config('mail.default'), ['log', 'array'], true),
        ])->layout('layouts.admin', ['title' => 'Alert Emails']);
    }
}
