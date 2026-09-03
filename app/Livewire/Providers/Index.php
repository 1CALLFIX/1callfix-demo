<?php

namespace App\Livewire\Providers;

use App\Imports\HeadingRowImport;
use App\Models\CatalogImportRun;
use App\Models\Provider;
use App\Services\AuthorizationService;
use App\Services\Onboarding\ProviderPreRegisterImporter;
use App\Support\Concerns\HasCsvExport;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

class Index extends Component
{
    use WithPagination;
    use WithFileUploads;
    use HasCsvExport;

    public string $statusFilter = 'pending';
    public string $search = '';
    /** Narrow to providers who are online right now — the dashboard's "Providers Online" card links here with this set. */
    public bool $onlineOnly = false;

    protected $queryString = ['statusFilter', 'search', 'onlineOnly'];

    // --- Bulk Pre-Register (Export Everywhere + Import Where It's Safe
    // session, Part 3) — see ProviderPreRegisterImporter's own docblock. ---
    public $providersPreregFile = null;
    public bool $showProvidersPrereg = false;
    public array $providersPreregErrors = [];
    public ?array $providersPreregRows = null;
    public ?string $providersPreregMessage = null;
    public ?CatalogImportRun $providersPreregRun = null;

    /** providers.view was seeded (2026_08_11_016000) but never checked -- see Commissions\Index's identical fix for the full reasoning. Not granted to the Operator role by design (its grant list stops at bookings.*), so Operator now correctly loses access here rather than before, when nothing enforced it. */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('providers.view'), 403, 'You do not have permission to view providers.');
    }

    public function updatingStatusFilter()
    {
        $this->resetPage();
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingOnlineOnly()
    {
        $this->resetPage();
    }

    // ============================= Bulk Pre-Register =============================

    public function toggleProvidersPrereg(): void
    {
        $this->showProvidersPrereg = ! $this->showProvidersPrereg;
        $this->providersPreregFile = null;
        $this->providersPreregErrors = [];
        $this->providersPreregRows = null;
        $this->providersPreregMessage = null;
        $this->providersPreregRun = null;
    }

    /**
     * VALIDATE -> SCOPE CHECK -> PREVIEW. Partial success by design (see
     * Products\Manage::validateProductsImport()'s identical reasoning) —
     * one bad row (or one out-of-scope franchise) never blocks the rest.
     */
    public function validateProvidersPrereg(): void
    {
        $this->providersPreregErrors = [];
        $this->providersPreregRows = null;
        $this->providersPreregMessage = null;
        $this->providersPreregRun = null;

        $this->validate(['providersPreregFile' => ['required', 'file', 'mimes:xlsx,xls,csv']]);

        $reader = new HeadingRowImport;
        Excel::import($reader, $this->providersPreregFile->getRealPath());

        $result = (new ProviderPreRegisterImporter(auth()->user()))->validateRows($reader->rows);

        $this->providersPreregErrors = $result['errors'];
        $this->providersPreregRows = $result['previewRows'] ?: null;
    }

    /** CONFIRM -> TRANSACTION-SAFE COMMIT -> REPORT. kyc_status is never set to 'approved' here — see ProviderPreRegisterImporter's own docblock. */
    public function commitProvidersPrereg(): void
    {
        if (empty($this->providersPreregRows)) {
            return;
        }

        if (! auth()->user()->hasPermissionAnywhere('providers.manage')) {
            $this->providersPreregErrors = [['row' => '-', 'field' => 'permission', 'message' => 'You do not have permission to bulk pre-register providers.']];
            return;
        }

        $fileName = $this->providersPreregFile?->getClientOriginalName();

        $this->providersPreregRun = (new ProviderPreRegisterImporter(auth()->user()))->commit(
            $this->providersPreregRows, auth()->user(), $fileName
        );

        if ($this->providersPreregRun->status === 'failed') {
            $this->providersPreregErrors = [['row' => '-', 'field' => 'commit', 'message' => 'Bulk pre-register failed, nothing was saved.']];
            return;
        }

        $this->providersPreregMessage = 'Bulk pre-register complete.';
        $this->providersPreregRows = null;
        $this->providersPreregFile = null;
    }

    private function scopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    /** Scope + the screen's own status/search filters, in one place — render() paginates it, exportProvidersCsv() streams every matching row unpaginated. */
    private function filteredProvidersQuery()
    {
        $scoped = fn ($query) => app(AuthorizationService::class)->scopeQuery($query, auth()->user(), 'providers.view', $this->scopeColumns());

        return $scoped(Provider::with(['user', 'zone', 'documents']))
            ->when($this->statusFilter, fn ($q) => $q->where('kyc_status', $this->statusFilter))
            ->when($this->onlineOnly, fn ($q) => $q->where('is_online', true))
            ->when($this->search, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$this->search}%")->orWhere('phone', 'like', "%{$this->search}%")));
    }

    /**
     * Blank CSV template for the Bulk Pre-Register upload — correct headers
     * plus one example row. Mirrors the "Download template" affordance the
     * catalog import screens (Services/Categories/Subcategories) already
     * offer; kept as a tiny inline stream here rather than a dedicated
     * Export class because the content is entirely static.
     */
    public function downloadPreregTemplate()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel doesn't mangle non-ASCII — see HasCsvExport.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['name', 'phone', 'franchise_id', 'zone_id']);
            fputcsv($out, ['Ravi Kumar', '+919000000001', '3', '17']);
            fclose($out);
        }, 'providers-prereg-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Export Everywhere session, Part 1 — current filtered + scoped view as CSV. */
    public function exportProvidersCsv()
    {
        return $this->streamCsvExport(
            'providers-filtered-'.now()->format('Y-m-d-His').'.csv',
            $this->filteredProvidersQuery(),
            ['id', 'name', 'phone', 'kyc_status', 'zone', 'is_online', 'rating_avg', 'jobs_completed', 'created_at'],
            fn (Provider $p) => [$p->id, $p->user?->name, $p->user?->phone, $p->kyc_status, $p->zone?->name, $p->is_online ? 1 : 0, $p->rating_avg, $p->jobs_completed, $p->created_at],
        );
    }

    public function render()
    {
        $scoped = fn ($query) => app(AuthorizationService::class)->scopeQuery($query, auth()->user(), 'providers.view', $this->scopeColumns());

        $providers = $this->filteredProvidersQuery()
            // Admin Polish + AI session, Part 1 item 3 — "should feel like a
            // queue to clear, not a spreadsheet". Pending applications are
            // now processed oldest-first (a real FIFO queue an operator
            // clears from the top down); Approved/Rejected stay newest-first
            // (browsing recent decisions is the natural order there).
            ->when($this->statusFilter === 'pending', fn ($q) => $q->oldest(), fn ($q) => $q->latest())
            ->paginate(20);

        $counts = $scoped(Provider::query())
            ->selectRaw('kyc_status, count(*) as total')
            ->groupBy('kyc_status')
            ->pluck('total', 'kyc_status');

        return view('livewire.providers.index', compact('providers', 'counts'))
            ->layout('layouts.admin', ['title' => 'Providers']);
    }
}
