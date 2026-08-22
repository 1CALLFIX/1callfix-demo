<?php

namespace App\Livewire\PaymentGateways;

use App\Models\PaymentGatewayConfig;
use App\Services\Payments\PaymentGatewayManager;
use Livewire\Component;

/**
 * Payment Gateway Manager session — the admin screen behind the new
 * `payment_gateways` table: add a gateway, paste credentials, set
 * test/live mode, activate. Global-only (no franchise/zone scope —
 * gateway config is a platform-wide financial control, same posture as
 * Badges\Manage's definitions section, which this screen's list+toggle
 * shape mirrors most closely of any existing admin screen).
 *
 * Gated by `payment_gateways.manage`, seeded (2026_08_25_002000) to
 * super_admin ONLY — no other role, unlike every other permission in this
 * catalog, which is deliberately grantable at any scope. This is the
 * strictest RBAC lock in the app on purpose.
 *
 * Credential inputs are write-only: the add form requires them, the edit
 * form starts every field blank and a blank field means "leave this value
 * unchanged" — the decrypted value is never redisplayed anywhere, same
 * discipline PaymentGatewayConfig::maskedCredentialSummary() enforces for
 * the read side.
 */
class Manage extends Component
{
    /**
     * Field key => input label, per driver. Symmetric on purpose (every
     * driver this platform knows about needs exactly 3 credential values
     * today) — see PaymentGatewayManager::driverFor() for how these keys
     * map onto each driver's constructor.
     */
    private const CREDENTIAL_FIELDS = [
        'razorpay' => ['key_id' => 'Key ID', 'key_secret' => 'Key Secret', 'webhook_secret' => 'Webhook Secret'],
        'paytm' => ['merchant_id' => 'Merchant ID', 'merchant_key' => 'Merchant Key', 'website' => 'Website (e.g. WEBSTAGING)'],
        'phonepe' => ['merchant_id' => 'Merchant ID', 'salt_key' => 'Salt Key', 'salt_index' => 'Salt Index'],
    ];

    // --- Add form ---
    public string $name = '';
    public string $driver = 'razorpay';
    public string $mode = 'test';
    public string $priority = '0';
    public array $credentialInputs = [];

    // --- Edit modal ---
    public bool $showEditModal = false;
    public ?int $editGatewayId = null;
    public string $editName = '';
    public string $editDriver = 'razorpay';
    public string $editMode = 'test';
    public string $editPriority = '0';
    public array $editCredentialInputs = [];

    // --- Delete confirmation ---
    public ?int $confirmingDeleteId = null;

    public string $flashMessage = '';
    public string $flashType = 'success';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('payment_gateways.manage'), 403, 'You do not have permission to view payment gateway configuration.');

        $this->credentialInputs = array_fill_keys(array_keys(self::CREDENTIAL_FIELDS[$this->driver]), '');
    }

    private function canManage(): bool
    {
        return auth()->user()->hasPermissionAnywhere('payment_gateways.manage');
    }

    public function updatedDriver(): void
    {
        $this->credentialInputs = array_fill_keys(array_keys(self::CREDENTIAL_FIELDS[$this->driver] ?? []), '');
    }

    public function updatedEditDriver(): void
    {
        $this->editCredentialInputs = array_fill_keys(array_keys(self::CREDENTIAL_FIELDS[$this->editDriver] ?? []), '');
    }

    public function credentialFieldsFor(string $driver): array
    {
        return self::CREDENTIAL_FIELDS[$driver] ?? [];
    }

    // ============================== Add ==============================

    public function save(): void
    {
        if (! $this->canManage()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage payment gateways.';
            return;
        }

        $fields = self::CREDENTIAL_FIELDS[$this->driver] ?? [];

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'driver' => ['required', 'in:'.implode(',', array_keys(self::CREDENTIAL_FIELDS))],
            'mode' => ['required', 'in:test,live'],
            'priority' => ['required', 'integer', 'min:0'],
        ];
        foreach (array_keys($fields) as $key) {
            $rules["credentialInputs.{$key}"] = ['required', 'string', 'max:500'];
        }
        $this->validate($rules);

        // Never created already-active — activation is its own explicit
        // step (activate()), which is where the ACTIVATABLE_DRIVERS guard
        // lives. Keeps "add a row" and "go live" clearly separate actions
        // for a screen this sensitive.
        PaymentGatewayConfig::create([
            'name' => $this->name,
            'driver' => $this->driver,
            'credentials' => $this->credentialInputs,
            'mode' => $this->mode,
            'is_active' => false,
            'priority' => (int) $this->priority,
        ]);

        $this->reset(['name', 'priority']);
        $this->driver = 'razorpay';
        $this->mode = 'test';
        $this->priority = '0';
        $this->credentialInputs = array_fill_keys(array_keys(self::CREDENTIAL_FIELDS['razorpay']), '');

        $this->flashType = 'success';
        $this->flashMessage = 'Gateway added. It is inactive until you activate it below.';
    }

    // ============================== Edit ==============================

    public function edit(int $gatewayId): void
    {
        $gateway = PaymentGatewayConfig::findOrFail($gatewayId);

        $this->editGatewayId = $gateway->id;
        $this->editName = $gateway->name;
        $this->editDriver = $gateway->driver;
        $this->editMode = $gateway->mode;
        $this->editPriority = (string) $gateway->priority;
        // Deliberately blank, not pre-filled with masked/decrypted values —
        // see the class docblock. update() below merges only the fields an
        // admin actually types into.
        $this->editCredentialInputs = array_fill_keys(array_keys(self::CREDENTIAL_FIELDS[$gateway->driver] ?? []), '');

        $this->resetValidation();
        $this->showEditModal = true;
    }

    public function update(): void
    {
        if (! $this->canManage()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage payment gateways.';
            return;
        }

        $fields = self::CREDENTIAL_FIELDS[$this->editDriver] ?? [];

        $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editMode' => ['required', 'in:test,live'],
            'editPriority' => ['required', 'integer', 'min:0'],
        ]);

        $gateway = PaymentGatewayConfig::findOrFail($this->editGatewayId);

        // Blank input = leave that credential unchanged. Driver itself is
        // NOT editable here (changing it would orphan the old credential
        // keys) — delete and re-add instead, same restraint as not
        // over-building a form for an edge case this sensitive.
        $existing = $gateway->credentials ?? [];
        $merged = $existing;
        foreach (array_keys($fields) as $key) {
            if (trim((string) ($this->editCredentialInputs[$key] ?? '')) !== '') {
                $merged[$key] = $this->editCredentialInputs[$key];
            }
        }

        $gateway->update([
            'name' => $this->editName,
            'mode' => $this->editMode,
            'priority' => (int) $this->editPriority,
            'credentials' => $merged,
        ]);

        $this->showEditModal = false;
        $this->editCredentialInputs = [];
        $this->flashType = 'success';
        $this->flashMessage = 'Gateway updated.';
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editCredentialInputs = [];
        $this->resetValidation();
    }

    // ============================== Activate / Deactivate ==============================

    /**
     * Rejects activation for any driver not in
     * PaymentGatewayManager::ACTIVATABLE_DRIVERS — the same allow-list
     * PaymentGatewayManager::active() itself checks, so this screen and
     * the actual resolution logic can never disagree about what's really
     * live. Deactivating is always allowed (never a safety concern).
     */
    public function toggleActive(int $gatewayId): void
    {
        if (! $this->canManage()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage payment gateways.';
            return;
        }

        $gateway = PaymentGatewayConfig::findOrFail($gatewayId);

        if (! $gateway->is_active && ! in_array($gateway->driver, PaymentGatewayManager::ACTIVATABLE_DRIVERS, true)) {
            $this->flashType = 'error';
            $this->flashMessage = ucfirst($gateway->driver).' is not yet available to activate — merchant onboarding is still pending.';
            return;
        }

        $gateway->update(['is_active' => ! $gateway->is_active]);
        $this->flashType = 'success';
        $this->flashMessage = 'Gateway '.($gateway->fresh()->is_active ? 'activated' : 'deactivated').'.';
    }

    // ============================== Delete ==============================

    public function confirmDelete(int $gatewayId): void
    {
        $this->confirmingDeleteId = $gatewayId;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    public function deleteGateway(): void
    {
        if (! $this->confirmingDeleteId) {
            return;
        }

        if (! $this->canManage()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage payment gateways.';
            $this->confirmingDeleteId = null;
            return;
        }

        PaymentGatewayConfig::findOrFail($this->confirmingDeleteId)->delete();

        $this->confirmingDeleteId = null;
        $this->flashType = 'success';
        $this->flashMessage = 'Gateway deleted.';
    }

    public function render()
    {
        $activeGateway = app(\App\Contracts\PaymentGateway::class);

        return view('livewire.payment-gateways.manage', [
            'gateways' => PaymentGatewayConfig::orderByDesc('priority')->orderBy('id')->get(),
            'knownDrivers' => app(PaymentGatewayManager::class)->knownDrivers(),
            'credentialFields' => self::CREDENTIAL_FIELDS,
            'activatableDrivers' => PaymentGatewayManager::ACTIVATABLE_DRIVERS,
            // Read-only "what's actually live right now" strip — the same
            // masked-status info Settings\Manage's Payment tab already
            // shows, surfaced here too since this is now the screen that
            // controls it.
            'currentlyResolvedGatewayName' => $activeGateway->displayName(),
            'currentlyResolvedGatewayConfigured' => $activeGateway->isConfigured(),
        ])->layout('layouts.admin', ['title' => 'Payment Gateways']);
    }
}
