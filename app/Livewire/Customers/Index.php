<?php

namespace App\Livewire\Customers;

use App\Imports\HeadingRowImport;
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
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter));
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

        return view('livewire.customers.index', compact('customers', 'currencySymbol'))
            ->layout('layouts.admin', ['title' => 'Customers']);
    }
}
