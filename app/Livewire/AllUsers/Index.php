<?php

namespace App\Livewire\AllUsers;

use App\Models\Franchise;
use App\Models\NotificationCampaign;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\ActivityLogger;
use App\Services\AudienceResolver;
use App\Services\AuthorizationService;
use App\Services\CampaignService;
use App\Support\Concerns\HasCsvExport;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Unified "All Users" Directory session, Part 1 — ADDITIVE, not a
 * replacement for Customers\Index/Providers\Index/Workers\Index, which
 * keep their own type-specific actions (KYC review, suspend/reactivate,
 * etc.) exactly as they are. This screen aggregates across all of them
 * for one thing: finding/messaging real people without hopping between
 * three separate screens.
 *
 * REAL SCHEMA (Step 0 of this session — see the session report for the
 * full investigation): there is ONE `users` table, discriminated by a
 * `role` enum (customer, provider, franchise_owner, zone_manager,
 * country_admin, city_admin, operator, support, super_admin — see
 * 2026_08_11_015000). "Customer" is role=customer with no separate
 * profile row. "Provider" and "Worker" are BOTH role=provider — the
 * distinction is which profile row(s) exist: `providers` (hasOne
 * Provider, User::providerProfile()) and/or `field_workers` (hasOne
 * FieldWorker, User::fieldWorkerProfile()) — User::fieldWorkerProfile()'s
 * own docblock confirms a user "may hold either, both, or neither".
 * "Staff/admin" is AudienceResolver::STAFF_ROLES, reused here rather than
 * redefined. No UNION query needed anywhere — it's the same `users` table
 * throughout, just role/relation-filtered.
 */
class Index extends Component
{
    use WithPagination;
    use HasCsvExport;

    public string $search = '';
    public string $typeFilter = ''; // ''|customer|provider|worker|staff
    public ?int $franchiseIdFilter = null;
    public ?int $zoneIdFilter = null;

    protected $queryString = ['search', 'typeFilter'];

    // --- Bulk select + notify (Part 2) ---
    /** @var array<int, int> */
    public array $selectedIds = [];
    public bool $showNotifyModal = false;
    public string $notifyStep = 'compose'; // compose|confirm
    public string $notifySubject = '';
    public string $notifyBody = '';
    /** @var array<string, bool> */
    public array $notifyChannels = ['mail' => true, 'sms' => false, 'push' => false, 'whatsapp' => false];
    public string $notifyFlashType = '';
    public string $notifyFlashMessage = '';
    public int $notifyConfirmedCount = 0;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('users.directory.view'), 403, 'You do not have permission to view the users directory.');
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingTypeFilter(): void { $this->resetPage(); }
    public function updatingFranchiseIdFilter(): void { $this->resetPage(); }
    public function updatingZoneIdFilter(): void { $this->resetPage(); }

    // ============================== Scope + filters ==============================

    /**
     * A user's geography lives in up to four different places depending on
     * WHICH kind of person they are — same reasoning AudienceResolver::
     * everyone() already documents for its own broadcast-audience query,
     * expressed here as scopeQuery()'s own "array of candidate paths, OR'd
     * together" convention (see AuthorizationService::scopeQuery()'s
     * docblock) rather than reimplemented:
     *   - staff: users.franchise_id/zone_id directly
     *   - provider: providers.franchise_id/zone_id (providerProfile)
     *   - worker: field_workers.franchise_id/zone_id (fieldWorkerProfile)
     *   - customer: their saved addresses' franchise_id/zone_id (customers
     *     carry no fixed home franchise/zone column of their own)
     */
    private function scopeColumns(): array
    {
        return [
            'zone_id' => ['zone_id', 'providerProfile.zone_id', 'fieldWorkerProfile.zone_id', 'addresses.zone_id'],
            'franchise_id' => ['franchise_id', 'providerProfile.franchise_id', 'fieldWorkerProfile.franchise_id', 'addresses.franchise_id'],
            'city_id' => ['franchise.city_id', 'providerProfile.franchise.city_id', 'fieldWorkerProfile.franchise.city_id', 'addresses.franchise.city_id'],
            'country_id' => ['franchise.country_id', 'providerProfile.franchise.country_id', 'fieldWorkerProfile.franchise.country_id', 'addresses.franchise.country_id'],
        ];
    }

    /**
     * Scope + every current filter, in one place — render() paginates it,
     * exportUsersCsv() streams it unpaginated, and the bulk-notify send
     * path re-runs the SAME scopeQuery() (via scopedSelectedIds() below)
     * against the raw selected ids. This is THE single most important
     * method in this class: every other read/write in this component goes
     * through it, so scope can never drift between "what's shown" and
     * "what's allowed".
     */
    private function filteredQuery()
    {
        $scoped = app(AuthorizationService::class)->scopeQuery(User::query(), auth()->user(), 'users.directory.view', $this->scopeColumns());

        return $scoped
            ->when($this->search !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")))
            ->when($this->typeFilter === 'customer', fn ($q) => $q->where('role', 'customer'))
            ->when($this->typeFilter === 'provider', fn ($q) => $q->where('role', 'provider')->whereHas('providerProfile'))
            ->when($this->typeFilter === 'worker', fn ($q) => $q->where('role', 'provider')->whereHas('fieldWorkerProfile'))
            ->when($this->typeFilter === 'staff', fn ($q) => $q->whereIn('role', AudienceResolver::STAFF_ROLES))
            ->when($this->franchiseIdFilter, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('franchise_id', $this->franchiseIdFilter)
                ->orWhereHas('providerProfile', fn ($p) => $p->where('franchise_id', $this->franchiseIdFilter))
                ->orWhereHas('fieldWorkerProfile', fn ($p) => $p->where('franchise_id', $this->franchiseIdFilter))
                ->orWhereHas('addresses', fn ($a) => $a->where('franchise_id', $this->franchiseIdFilter))))
            ->when($this->zoneIdFilter, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('zone_id', $this->zoneIdFilter)
                ->orWhereHas('providerProfile', fn ($p) => $p->where('zone_id', $this->zoneIdFilter))
                ->orWhereHas('fieldWorkerProfile', fn ($p) => $p->where('zone_id', $this->zoneIdFilter))
                ->orWhereHas('addresses', fn ($a) => $a->where('zone_id', $this->zoneIdFilter))));
    }

    public function typeLabel(User $user): string
    {
        if ($user->role === 'customer') {
            return 'Customer';
        }

        if (in_array($user->role, AudienceResolver::STAFF_ROLES, true)) {
            return ucwords(str_replace('_', ' ', $user->role));
        }

        if ($user->role === 'provider') {
            $isProvider = $user->relationLoaded('providerProfile') ? $user->providerProfile !== null : $user->providerProfile()->exists();
            $isWorker = $user->relationLoaded('fieldWorkerProfile') ? $user->fieldWorkerProfile !== null : $user->fieldWorkerProfile()->exists();

            $labels = array_filter([$isProvider ? 'Provider' : null, $isWorker ? 'Worker' : null]);

            return $labels ? implode(' + ', $labels) : 'Provider (no profile)';
        }

        return ucfirst($user->role);
    }

    /**
     * Read-only display figure — deliberately NOT WalletService::balance()
     * (which does firstOrCreate(), a real WRITE, on every call). Customers\
     * Index already accepts that write-per-row cost for its own 20-row
     * page; this screen can show more roles across a wider filter set, so
     * a read-only `$user->wallet?->balance` (eager-loaded, zero extra
     * queries) avoids amplifying it — a user with literally no wallet row
     * yet has a real balance of 0 either way, nothing is being hidden.
     */
    private function walletBalanceDisplay(User $user): ?float
    {
        if ($user->role !== 'customer' && $user->role !== 'provider') {
            return null; // wallet doesn't conceptually apply to staff/admin rows
        }

        return (float) ($user->wallet?->balance ?? 0);
    }

    // ============================== Export (Part 1) ==============================

    /** Export Everywhere pattern, reused as-is — current filtered + scoped view as CSV. */
    public function exportUsersCsv()
    {
        return $this->streamCsvExport(
            'all-users-filtered-'.now()->format('Y-m-d-His').'.csv',
            $this->filteredQuery()->with(['providerProfile', 'fieldWorkerProfile', 'wallet']),
            ['id', 'name', 'phone', 'email', 'type', 'status', 'wallet_balance', 'created_at'],
            fn (User $u) => [$u->id, $u->name, $u->phone, $u->email, $this->typeLabel($u), $u->status, $this->walletBalanceDisplay($u), $u->created_at],
        );
    }

    // ============================== Growth chart (Part 3, best effort) ==============================

    /**
     * Same server-rendered inline-SVG technique as Dashboard::sevenDayTrend()
     * — no new charting dependency. Respects the CURRENT filters (type/
     * franchise/zone/search), same as every other figure on this screen.
     * "Provider online-status trend" (the prompt's other candidate) is
     * deliberately NOT built — providers.is_online is a live boolean with
     * no history table capturing past values anywhere in this schema, so
     * there is no real data to trend; see the session report.
     */
    private function sevenDayGrowth(): array
    {
        $start = now()->copy()->subDays(6)->startOfDay();

        $byDay = (clone $this->filteredQuery())
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as d, count(*) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $start->copy()->addDays(6 - $i);
            $key = $day->toDateString();
            $days[] = ['date' => $key, 'label' => $day->format('D'), 'count' => (int) ($byDay[$key] ?? 0)];
        }

        return $days;
    }

    // ============================== Bulk select ==============================

    public function toggleSelect(int $userId): void
    {
        if (in_array($userId, $this->selectedIds, true)) {
            $this->selectedIds = array_values(array_diff($this->selectedIds, [$userId]));
        } else {
            $this->selectedIds[] = $userId;
        }
    }

    public function clearSelection(): void
    {
        $this->selectedIds = [];
    }

    // ============================== Bulk notify (Part 2) ==============================

    /** Which channels have a REAL (non-log) driver configured right now — the compose modal labels each accordingly, per the mission spec ("clearly label any log-driver channel... so nobody thinks a message went out when it was only logged"). */
    public function channelAvailability(): array
    {
        return [
            'mail' => config('mail.default') !== 'log',
            'sms' => config('services.sms.driver', 'log') !== 'log',
            'push' => config('services.push.driver', 'log') !== 'log',
            'whatsapp' => config('services.whatsapp.driver', 'log') !== 'log',
        ];
    }

    /**
     * The ids the acting admin ACTUALLY has notification.send coverage
     * for, re-derived from the raw selection via the exact same
     * scopeQuery() the directory's own view uses (scopeColumns()) — never
     * trusts $this->selectedIds directly for a send decision. Checked
     * against 'notification.send' specifically (not 'users.directory.view')
     * so viewing and sending can be granted at different scopes, same
     * split every other screen already makes between its own .view and
     * .manage permissions.
     */
    private function scopedSelectedIds(): array
    {
        if (empty($this->selectedIds)) {
            return [];
        }

        return app(AuthorizationService::class)
            ->scopeQuery(User::query()->whereIn('id', $this->selectedIds), auth()->user(), 'notification.send', $this->scopeColumns())
            ->pluck('id')->all();
    }

    public function openNotifyModal(): void
    {
        $this->notifyFlashType = '';
        $this->notifyFlashMessage = '';

        if (empty($this->selectedIds)) {
            $this->notifyFlashType = 'error';
            $this->notifyFlashMessage = 'Select at least one person first.';
            return;
        }

        if (! auth()->user()->hasPermissionAnywhere('notification.send') || ! auth()->user()->hasPermissionAnywhere('notification.direct')) {
            $this->notifyFlashType = 'error';
            $this->notifyFlashMessage = 'You do not have permission to send notifications.';
            return;
        }

        $this->notifyStep = 'compose';
        $this->notifySubject = '';
        $this->notifyBody = '';
        $this->notifyChannels = ['mail' => true, 'sms' => false, 'push' => false, 'whatsapp' => false];
        $this->resetValidation();
        $this->showNotifyModal = true;
    }

    public function closeNotifyModal(): void
    {
        $this->showNotifyModal = false;
        $this->notifyStep = 'compose';
        $this->resetValidation();
    }

    /**
     * COMPOSE -> CONFIRM. "a confirmation step showing 'this will message
     * 47 people' before actually sending" — mission spec, verbatim. The
     * scope re-check happens HERE too (not only inside confirmSend()) so
     * the confirmation screen's own headline number is never inflated by
     * rows the actor can't really message.
     */
    public function reviewNotify(): void
    {
        $this->validate([
            'notifySubject' => ['required', 'string', 'max:255'],
            'notifyBody' => ['required', 'string', 'max:5000'],
        ], [], ['notifySubject' => 'subject', 'notifyBody' => 'message']);

        if (! collect($this->notifyChannels)->contains(true)) {
            $this->addError('notifyChannels', 'Choose at least one channel.');
            return;
        }

        $scopedIds = $this->scopedSelectedIds();

        if (empty($scopedIds) || count($scopedIds) !== count($this->selectedIds)) {
            $this->notifyFlashType = 'error';
            $this->notifyFlashMessage = 'One or more selected people are outside your permitted scope — nothing was sent.';
            return;
        }

        $this->notifyConfirmedCount = count($scopedIds);
        $this->notifyStep = 'confirm';
    }

    public function backToCompose(): void
    {
        $this->notifyStep = 'compose';
    }

    /**
     * The actual send — refuses to run unless reviewNotify() already
     * moved this to the 'confirm' step (see BulkNotifyTest::
     * test_confirm_send_is_refused_without_first_reviewing()). Re-checks
     * BOTH the blanket permission and the per-row scope again here, never
     * relying solely on reviewNotify()'s earlier pass — the two calls are
     * separate requests in a real browser session, and selection state
     * could in principle change between them.
     */
    public function confirmSend(): void
    {
        if ($this->notifyStep !== 'confirm') {
            $this->notifyFlashType = 'error';
            $this->notifyFlashMessage = 'Review the recipient count before sending.';
            return;
        }

        if (! auth()->user()->hasPermissionAnywhere('notification.send') || ! auth()->user()->hasPermissionAnywhere('notification.direct')) {
            $this->notifyFlashType = 'error';
            $this->notifyFlashMessage = 'You do not have permission to send notifications.';
            return;
        }

        $scopedIds = $this->scopedSelectedIds();

        if (empty($scopedIds) || count($scopedIds) !== count($this->selectedIds)) {
            $this->notifyFlashType = 'error';
            $this->notifyFlashMessage = 'One or more selected people are outside your permitted scope — nothing was sent.';
            return;
        }

        $channels = collect($this->notifyChannels)->filter()->keys()->all();

        $campaign = NotificationCampaign::create([
            'category' => 'business',
            'type' => 'admin_direct_message',
            'title' => $this->notifySubject,
            'message' => $this->notifyBody,
            'recipient_type' => 'selected_users',
            'scope_type' => 'global',
            'filters' => ['user_ids' => $scopedIds],
            'channels' => implode(',', $channels),
            'priority' => 'normal',
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]);

        try {
            app(CampaignService::class)->send($campaign);

            // "who sent what (subject, channel, recipient count) and
            // when" — mission spec, verbatim. activity_log already exists
            // (Phase P3) and is the established audit mechanism every
            // other admin write action uses (Modules\Manage, Chat\Manage,
            // Operations\Health) — reused here, not a new log table.
            ActivityLogger::logModel(
                auth()->user(),
                $campaign,
                sprintf('Bulk-notified %d user(s) via %s: "%s"', count($scopedIds), implode('/', $channels), $this->notifySubject),
                ['recipient_ids' => $scopedIds, 'channels' => $channels, 'subject' => $this->notifySubject]
            );

            $this->notifyFlashType = 'success';
            $this->notifyFlashMessage = "Sent to {$campaign->fresh()->recipient_count} recipient(s).";
            $this->selectedIds = [];
            $this->showNotifyModal = false;
            $this->notifyStep = 'compose';
        } catch (\Throwable $e) {
            $this->notifyFlashType = 'error';
            $this->notifyFlashMessage = $e->getMessage();
        }
    }

    public function render()
    {
        $users = $this->filteredQuery()
            ->with(['providerProfile', 'fieldWorkerProfile', 'wallet', 'franchise'])
            ->latest()
            ->paginate(20);

        return view('livewire.all-users.index', [
            'users' => $users,
            'franchises' => Franchise::orderBy('name')->get(['id', 'name']),
            'zones' => Zone::where('is_active', true)->orderBy('name')->get(['id', 'name', 'franchise_id']),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
            'channelAvailability' => $this->channelAvailability(),
            'growth' => $this->sevenDayGrowth(),
        ])->layout('layouts.admin', ['title' => 'All Users']);
    }
}
