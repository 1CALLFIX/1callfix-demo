<?php

namespace App\Livewire\CancellationPolicy;

use App\Actions\ResolveInterimDisputeAction;
use App\Actions\WaiveCancellationChargeAction;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\BookingCancellationRequest;
use App\Models\Franchise;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Models\Zone;
use App\Services\Cancellation\CancellationPolicy;
use App\Services\Cancellation\PolicySettings;
use App\Services\Cancellation\SparesDelayClock;
use App\Services\SettingsAuditor;
use App\Support\SuperAdminGate;
use Livewire\Component;

/**
 * REF 1CF-CANCEL-POLICY-001 — Super Admin → Cancellation Policy. ONE screen for every value the customer
 * cancellation policy reads (PolicySettings::REGISTRY), plus the operator queues the policy creates.
 *
 *   Settings            every key, validated (no negatives, % in 0–100), written through SettingsAuditor
 *                       (admin, key, old, new, time → activity_log), with a live preview of the customer-facing text.
 *   Category overrides  per-category spares-delay days with a category picker, add / remove.
 *   Operations          bookings held for spares (days counted), unpaid cancellation charges (waive with a reason),
 *                       disputes awaiting resolution.
 *   Audit log           every change to these keys.
 *
 * Blank = "not configured" (falls back to the default, or keeps a provider-side rule inactive); 0 = explicitly zero.
 * Every action re-checks SuperAdminGate server-side.
 */
class Manage extends Component
{
    public string $tab = 'settings'; // settings|categories|operations|audit

    public string $scopeType = 'global'; // global|franchise|zone
    public ?int $scopeFranchiseId = null;
    public ?int $scopeZoneId = null;

    /** Keyed by self::field($settingKey) — Livewire treats dots in a property path as nesting. */
    public array $inputs = [];

    public ?int $overrideCategoryId = null;
    public string $overrideDays = '';

    /** @var array<int, string> request id => reason */
    public array $waiveReasons = [];
    /** @var array<int, string> booking id => resolution note */
    public array $resolutions = [];

    public string $flashMessage = '';
    public string $flashType = 'success';

    protected $queryString = ['tab'];

    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function mount(): void
    {
        SuperAdminGate::authorize(auth()->user());
        $this->loadInputs();
    }

    public function setTab(string $tab): void
    {
        SuperAdminGate::authorize(auth()->user());
        $this->tab = in_array($tab, ['settings', 'categories', 'operations', 'audit'], true) ? $tab : 'settings';
        $this->flashMessage = '';
    }

    // ═════════════════════════════ scope ═════════════════════════════

    public function updatedScopeType(): void
    {
        $this->scopeFranchiseId = null;
        $this->scopeZoneId = null;
        $this->loadInputs();
    }

    public function updatedScopeFranchiseId(): void
    {
        $this->scopeZoneId = null;
        $this->loadInputs();
    }

    public function updatedScopeZoneId(): void
    {
        $this->loadInputs();
    }

    /** @return array{0: string, 1: ?int}|null null while the picked scope is incomplete */
    private function scopeTarget(): ?array
    {
        return match ($this->scopeType) {
            'global' => ['global', null],
            'franchise' => $this->scopeFranchiseId ? ['franchise', $this->scopeFranchiseId] : null,
            'zone' => $this->scopeZoneId ? ['zone', $this->scopeZoneId] : null,
            default => null,
        };
    }

    /** What is stored at EXACTLY this scope ('' = unset here, inherits). */
    private function loadInputs(): void
    {
        [$type, $id] = $this->scopeTarget() ?? ['global', null];
        $this->inputs = [];

        foreach (PolicySettings::REGISTRY as $key => $meta) {
            $row = Setting::where('scope_type', $type)->where('scope_id', $id)->where('key', $key)->value('value');
            $this->inputs[self::field($key)] = $row === null ? '' : (string) $row;
        }
    }

    // ═════════════════════════════ save ═════════════════════════════

    public function save(): void
    {
        SuperAdminGate::authorize(auth()->user());
        $this->resetErrorBag();
        $this->flashMessage = '';

        $target = $this->scopeTarget();
        if ($target === null) {
            $this->flash('Pick the franchise / zone first.', 'error');

            return;
        }

        $visitType = trim((string) ($this->inputs[self::field('cancellation.visit_fee_type')] ?? '')) ?: 'flat';
        $errors = 0;

        foreach (PolicySettings::REGISTRY as $key => $meta) {
            $error = PolicySettings::validate($key, $this->inputs[self::field($key)] ?? null, $visitType);
            if ($error) {
                $this->addError('inputs.'.self::field($key), $error);
                $errors++;
            }
        }

        if ($errors > 0) {
            $this->flash("Nothing was saved — fix the {$errors} highlighted value(s).", 'error');

            return;
        }

        $changed = 0;
        foreach (PolicySettings::REGISTRY as $key => $meta) {
            $changed += SettingsAuditor::put(auth()->user(), $key, $this->inputs[self::field($key)] ?? null, $target[0], $target[1]) ? 1 : 0;
        }

        $this->loadInputs();
        $this->flash($changed ? "Saved — {$changed} change(s) audit-logged." : 'No changes to save.', 'success');
    }

    // ═════════════════════════════ category overrides ═════════════════════════════

    public function addOverride(): void
    {
        SuperAdminGate::authorize(auth()->user());
        $this->resetErrorBag();

        if (! $this->overrideCategoryId || ! ServiceCategory::whereKey($this->overrideCategoryId)->exists()) {
            $this->addError('overrideCategoryId', 'Pick a category.');

            return;
        }
        $days = trim($this->overrideDays);
        if ($days === '' || ! ctype_digit($days) || (int) $days < 1) {
            $this->addError('overrideDays', 'Enter a whole number of days, 1 or more.');

            return;
        }

        SettingsAuditor::put(auth()->user(), PolicySettings::CATEGORY_OVERRIDE_PREFIX.$this->overrideCategoryId, $days);
        $this->overrideCategoryId = null;
        $this->overrideDays = '';
        $this->flash('Category override saved.', 'success');
    }

    public function removeOverride(int $categoryId): void
    {
        SuperAdminGate::authorize(auth()->user());
        SettingsAuditor::put(auth()->user(), PolicySettings::CATEGORY_OVERRIDE_PREFIX.$categoryId, null);
        $this->flash('Override removed — the category now follows the global value.', 'success');
    }

    // ═════════════════════════════ operations ═════════════════════════════

    public function waive(int $requestId, WaiveCancellationChargeAction $action): void
    {
        SuperAdminGate::authorize(auth()->user());

        try {
            $action->execute($requestId, auth()->user(), (string) ($this->waiveReasons[$requestId] ?? ''));
        } catch (\RuntimeException $e) {
            $this->flash($e->getMessage(), 'error');

            return;
        }

        unset($this->waiveReasons[$requestId]);
        $this->flash('Charge waived and logged.', 'success');
    }

    public function resolveDispute(int $bookingId, ResolveInterimDisputeAction $action): void
    {
        SuperAdminGate::authorize(auth()->user());

        try {
            $action->execute($bookingId, auth()->user(), (string) ($this->resolutions[$bookingId] ?? ''));
        } catch (\RuntimeException $e) {
            $this->flash($e->getMessage(), 'error');

            return;
        }

        unset($this->resolutions[$bookingId]);
        $this->flash('Dispute resolved.', 'success');
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }

    // ═════════════════════════════ render ═════════════════════════════

    public function render(CancellationPolicy $policy, SparesDelayClock $clock)
    {
        SuperAdminGate::authorize(auth()->user());

        $data = ['groups' => collect(PolicySettings::REGISTRY)->map(fn ($m, $k) => $m + ['key' => $k, 'field' => self::field($k)])->groupBy('group')];

        if ($this->tab === 'settings') {
            $raw = [];
            foreach (PolicySettings::REGISTRY as $key => $meta) {
                $typed = trim((string) ($this->inputs[self::field($key)] ?? ''));
                // An unset value at a narrower scope previews as the inherited one.
                $raw[$key] = $typed !== '' ? $typed : Setting::get($key, null, []);
            }
            $data['preview'] = $policy->policyLines(null, $raw);
            $data['franchises'] = Franchise::orderBy('name')->get(['id', 'name']);
            $data['zones'] = $this->scopeFranchiseId ? Zone::where('franchise_id', $this->scopeFranchiseId)->orderBy('name')->get(['id', 'name']) : collect();
        }

        if ($this->tab === 'categories') {
            $prefix = PolicySettings::CATEGORY_OVERRIDE_PREFIX;
            $overrides = Setting::where('scope_type', 'global')->where('key', 'like', $prefix.'%')->get()
                ->filter(fn ($s) => PolicySettings::isCategoryOverrideKey($s->key))
                ->mapWithKeys(fn ($s) => [(int) substr($s->key, strlen($prefix)) => $s->value]);
            $names = ServiceCategory::whereIn('id', $overrides->keys())->pluck('name', 'id');
            $data['overrides'] = $overrides->map(fn ($days, $id) => ['id' => $id, 'name' => $names[$id] ?? "Category #{$id}", 'days' => $days]);
            $data['categories'] = ServiceCategory::orderBy('name')->get(['id', 'name']);
            $data['globalDays'] = PolicySettings::current('cancellation.spares_delay_days');
        }

        if ($this->tab === 'operations') {
            $data['held'] = Booking::query()->where('status', 'on_hold')->where('hold_reason', 'awaiting_spares')
                ->with(['customer:id,name', 'provider.user:id,name'])->latest('on_hold_since')->limit(100)->get()
                ->map(fn (Booking $b) => [
                    'booking' => $b,
                    'days' => round($clock->countedSeconds($b) / 86400, 1),
                    'limit' => $clock->thresholdDays($b),
                    'unlocked' => $clock->unlocked($b),
                ]);
            $data['pending'] = BookingCancellationRequest::query()->whereIn('status', ['awaiting_payment', 'awaiting_admin'])
                ->with('booking.customer:id,name')->orderBy('due_by')->limit(100)->get();
            $data['disputes'] = Booking::query()->where('interim_dispute_status', 'open')->with('customer:id,name')->latest('interim_disputed_at')->limit(100)->get();
        }

        if ($this->tab === 'audit') {
            $data['audit'] = ActivityLog::query()->with('causer:id,name')
                ->where('subject_type', 'setting')
                ->where(fn ($q) => $q->where('properties->key', 'like', 'cancellation.%')->orWhere('properties->key', 'booking.extra_work_timeout_hours'))
                ->latest('id')->limit(200)->get();
        }

        return view('livewire.cancellation-policy.manage', $data);
    }
}
