<?php

namespace App\Livewire\Customers;

use App\Imports\HeadingRowImport;
use App\Livewire\Concerns\HasRowArchive;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use App\Models\CatalogImportRun;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\Onboarding\CustomerPreRegisterImporter;
use App\Services\WalletService;
use App\Support\Concerns\HasCsvExport;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

/** Customers were only ever visible embedded in a Booking's detail page -- the first standalone list/management screen for them. */
class Index extends Component
{
    use WithPagination;
    use WithFileUploads;
    use HasCsvExport;
    use HasRowArchive;

    // --- Edit modal (REF 1CF-ADMIN-ROWACTIONS-001) ---
    public bool $showEditModal = false;
    public ?int $editId = null;
    public string $editName = '';
    public string $editEmail = '';
    public string $editPhone = '';
    public string $editStatus = 'active';

    public string $search = '';
    public string $statusFilter = '';

    protected $queryString = ['search', 'statusFilter'];

    // --- Bulk Pre-Register (Export Everywhere + Import Where It's Safe
    // session, Part 3) — deliberately NOT called "import" anywhere in this
    // component/its view: see CustomerPreRegisterImporter's own docblock
    // for exactly what this does and does not do. ---
    public $customersPreregFile = null;
    public bool $showCustomersPrereg = false;
    public array $customersPreregErrors = [];
    public ?array $customersPreregRows = null;
    public ?string $customersPreregMessage = null;
    public ?CatalogImportRun $customersPreregRun = null;

    /** customers.view was seeded (2026_08_11_049000) but never checked -- see Commissions\Index's identical fix for the full reasoning. Distinct from customers.manage, which already gates Customers\Show's suspend/reactivate action AND (new, this session) bulk pre-register. */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('customers.view'), 403, 'You do not have permission to view customers.');
    }

    protected function archiveModel(): string
    {
        return User::class;
    }

    /** Customers only (never staff/providers via a crafted id), and only within the admin's own scope. */
    protected function canArchiveRow(Model $row): bool
    {
        return $row->role === 'customer'
            && auth()->user()->hasPermission('customers.manage', array_filter([
                'zone_id' => $row->zone_id,
                'franchise_id' => $row->franchise_id,
            ]));
    }

    protected function archiveWarning(Model $row): ?string
    {
        $open = $row->bookings()->whereNotIn('status', ['completed', 'cancelled'])->count();
        $balance = app(WalletService::class)->balance($row);
        $notes = [];
        if ($open > 0) {
            $notes[] = "{$open} open booking".($open === 1 ? '' : 's');
        }
        if ($balance > 0) {
            $notes[] = 'wallet balance '.number_format($balance, 2);
        }

        return $notes ? 'Heads-up: this customer has '.implode(' and ', $notes).'.' : null;
    }

    public function editCustomer(int $id): void
    {
        $user = User::findOrFail($id);
        abort_unless($this->canArchiveRow($user), 403);

        $this->editId = $user->id;
        $this->editName = $user->name;
        $this->editEmail = $user->email ?? '';
        $this->editPhone = $user->phone ?? '';
        $this->editStatus = $user->status;
        $this->resetValidation();
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->resetValidation();
    }

    public function saveCustomer(): void
    {
        $user = User::findOrFail($this->editId);
        abort_unless($this->canArchiveRow($user), 403);

        $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editEmail' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'editPhone' => ['required', 'string', 'max:32', Rule::unique('users', 'phone')->ignore($user->id)],
            'editStatus' => ['required', Rule::in(['active', 'suspended', 'pending_verification'])],
        ], [], ['editName' => 'name', 'editEmail' => 'email', 'editPhone' => 'phone', 'editStatus' => 'status']);

        $before = $user->only(['name', 'email', 'phone', 'status']);
        $user->forceFill([
            'name' => $this->editName,
            'email' => $this->editEmail !== '' ? $this->editEmail : null,
            'phone' => $this->editPhone,
            'status' => $this->editStatus,
        ])->save();

        ActivityLogger::logModel(auth()->user(), $user, 'customer edited', ['before' => $before, 'after' => $user->only(['name', 'email', 'phone', 'status'])]);
        $this->showEditModal = false;
        $this->rowFlash('success', 'Customer updated.');
    }

    public function updatingSearch() { $this->resetPage(); }
    public function updatingStatusFilter() { $this->resetPage(); }

    // ============================= Bulk Pre-Register =============================

    public function toggleCustomersPrereg(): void
    {
        $this->showCustomersPrereg = ! $this->showCustomersPrereg;
        $this->customersPreregFile = null;
        $this->customersPreregErrors = [];
        $this->customersPreregRows = null;
        $this->customersPreregMessage = null;
        $this->customersPreregRun = null;
    }

    /**
     * VALIDATE -> PREVIEW, via CustomerPreRegisterImporter (see its own
     * docblock — creates PENDING account shells only, never phone-verified).
     * Partial success by design: both errors and previewRows are set
     * whenever each is non-empty, so one bad row never blocks the rest —
     * same discipline as Products\Manage::validateProductsImport().
     */
    public function validateCustomersPrereg(): void
    {
        $this->customersPreregErrors = [];
        $this->customersPreregRows = null;
        $this->customersPreregMessage = null;
        $this->customersPreregRun = null;

        $this->validate(['customersPreregFile' => ['required', 'file', 'mimes:xlsx,xls,csv']]);

        $reader = new HeadingRowImport;
        Excel::import($reader, $this->customersPreregFile->getRealPath());

        $result = (new CustomerPreRegisterImporter)->validateRows($reader->rows);

        $this->customersPreregErrors = $result['errors'];
        $this->customersPreregRows = $result['previewRows'] ?: null;
    }

    /** CONFIRM -> TRANSACTION-SAFE COMMIT -> REPORT. Nothing written until this runs. */
    public function commitCustomersPrereg(): void
    {
        if (empty($this->customersPreregRows)) {
            return;
        }

        if (! auth()->user()->hasPermission('customers.manage')) {
            $this->customersPreregErrors = [['row' => '-', 'field' => 'permission', 'message' => 'You do not have permission to bulk pre-register customers.']];
            return;
        }

        $fileName = $this->customersPreregFile?->getClientOriginalName();

        $this->customersPreregRun = (new CustomerPreRegisterImporter)->commit(
            $this->customersPreregRows, auth()->user(), $fileName
        );

        if ($this->customersPreregRun->status === 'failed') {
            $this->customersPreregErrors = [['row' => '-', 'field' => 'commit', 'message' => 'Bulk pre-register failed, nothing was saved.']];
            return;
        }

        $this->customersPreregMessage = 'Bulk pre-register complete.';
        $this->customersPreregRows = null;
        $this->customersPreregFile = null;
    }

    /** Scope + the screen's own search/status filters, in one place — render() paginates it, exportCustomersCsv() streams every matching row unpaginated. */
    private function filteredCustomersQuery()
    {
        $columns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
        $scoped = app(AuthorizationService::class)->scopeQuery(User::query(), auth()->user(), 'customers.view', $columns);

        return $scoped->where('role', 'customer')
            ->when($this->search, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")))
            ->when($this->statusFilter === 'archived', fn ($q) => $q->onlyTrashed())
            ->when($this->statusFilter !== '' && $this->statusFilter !== 'archived', fn ($q) => $q->where('status', $this->statusFilter));
    }

    /** Export Everywhere session, Part 1 — current filtered + scoped view as CSV. Wallet balance excluded (a live computed value, not a stored column — same reasoning it's computed per-page in render() below rather than exported as a stale figure). */
    public function exportCustomersCsv()
    {
        return $this->streamCsvExport(
            'customers-filtered-'.now()->format('Y-m-d-His').'.csv',
            $this->filteredCustomersQuery()->withCount('bookings'),
            ['id', 'name', 'phone', 'email', 'status', 'bookings_count', 'created_at'],
            fn (User $c) => [$c->id, $c->name, $c->phone, $c->email, $c->status, $c->bookings_count, $c->created_at],
        );
    }

    public function render()
    {
        $customers = $this->filteredCustomersQuery()
            ->withCount('bookings')
            ->latest()
            ->paginate(20);

        $walletService = app(WalletService::class);
        $customers->getCollection()->transform(function ($c) use ($walletService) {
            $c->wallet_balance = $walletService->balance($c);
            return $c;
        });

        $currencySymbol = Setting::get('locale.currency_symbol', '₹');

        $canManage = auth()->user()->hasPermissionAnywhere('customers.manage');
        $canForce = $this->isSuperAdminUser();
        $archiveBars = $this->archiveBars();

        return view('livewire.customers.index', compact('customers', 'currencySymbol', 'canManage', 'canForce', 'archiveBars'))
            ->layout('layouts.admin', ['title' => 'Customers']);
    }
}
