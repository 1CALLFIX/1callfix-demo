<?php

namespace App\Livewire\Services;

use App\Exports\ServicesExport;
use App\Imports\HeadingRowImport;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;

class Index extends Component
{
    use WithFileUploads;

    public string $categoryId = '';
    public string $subcategoryId = '';

    public $importFile = null;
    public bool $showImport = false;
    public array $importErrors = [];
    public ?array $importRows = null;
    public ?string $importMessage = null;

    public function mount(): void
    {
        // Filters coming from Categories\Index's "View Services" link
        // (?subcategoryId=..) — same query-string caveat as Subcategories\Form:
        // this route has no {categoryId}/{subcategoryId} segments, so both are
        // read directly rather than declared as typed mount() parameters
        // (which would silently never get auto-injected).
        if ($id = request()->query('categoryId')) {
            $this->categoryId = (string) $id;
        }
        if ($id = request()->query('subcategoryId')) {
            $this->subcategoryId = (string) $id;
        }
    }

    public function toggleActive(int $serviceId): void
    {
        $service = Service::findOrFail($serviceId);
        $service->update(['is_active' => ! $service->is_active]);
    }

    public function exportServices()
    {
        return Excel::download(new ServicesExport, 'services-'.now()->format('Y-m-d').'.xlsx');
    }

    public function downloadServicesTemplate()
    {
        return Excel::download(new ServicesExport(templateOnly: true), 'services-template.xlsx');
    }

    public function toggleImport(): void
    {
        $this->showImport = ! $this->showImport;
        $this->importFile = null;
        $this->importErrors = [];
        $this->importRows = null;
        $this->importMessage = null;
    }

    public function validateServicesImport(): void
    {
        $this->importErrors = [];
        $this->importRows = null;
        $this->importMessage = null;

        $this->validate(['importFile' => ['required', 'file', 'mimes:xlsx,xls,csv']]);

        $reader = new HeadingRowImport;
        Excel::import($reader, $this->importFile->getRealPath());

        $errors = [];
        $validRows = [];

        foreach ($reader->rows as $i => $row) {
            $rowNum = $i + 2;

            // Accept Glover's original column names as aliases so its real
            // export files import unmodified — see ServicesExport's docblock
            // for the full rationale on each rename.
            $basePrice = $row['base_price'] ?? $row['price'] ?? null;
            $coverImage = $row['cover_image'] ?? $row['photo'] ?? null;
            $priceTypeRaw = $row['price_type'] ?? $row['duration'] ?? null;

            $validator = Validator::make([
                'category_id' => $row['category_id'] ?? null,
                'subcategory_id' => $this->blankToNull($row['subcategory_id'] ?? null),
                'name' => $row['name'] ?? null,
                'base_price' => $basePrice,
                'discount_price' => $this->blankToNull($row['discount_price'] ?? null),
            ], [
                'category_id' => ['required', 'integer', 'exists:service_categories,id'],
                'subcategory_id' => ['nullable', 'integer', 'exists:service_subcategories,id'],
                'name' => ['required', 'string', 'max:255'],
                'base_price' => ['required', 'numeric', 'min:0'],
                'discount_price' => ['nullable', 'numeric', 'min:0', 'lt:base_price'],
            ], [
                'category_id.exists' => 'category_id :input does not exist — import categories first.',
                'subcategory_id.exists' => 'subcategory_id :input does not exist — import subcategories first.',
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->messages() as $field => $messages) {
                    $errors[] = ['row' => $rowNum, 'field' => $field, 'message' => $messages[0]];
                }
                continue;
            }

            // A subcategory must actually belong to the given category — same
            // integrity rule the manual Create/Edit form enforces via its
            // dependent dropdown.
            $subcategoryId = $this->blankToNull($row['subcategory_id'] ?? null);
            if ($subcategoryId) {
                $belongs = ServiceSubcategory::where('id', $subcategoryId)
                    ->where('category_id', $row['category_id'])
                    ->exists();
                if (! $belongs) {
                    $errors[] = ['row' => $rowNum, 'field' => 'subcategory_id', 'message' => "subcategory_id {$subcategoryId} does not belong to category_id {$row['category_id']}."];
                    continue;
                }
            }

            $validRows[] = [
                'id' => $this->blankToNull($row['id'] ?? null),
                'category_id' => $row['category_id'],
                'subcategory_id' => $subcategoryId,
                'name' => $row['name'],
                'description' => $this->blankToNull($row['description'] ?? null),
                'base_price' => $basePrice,
                'discount_price' => $this->blankToNull($row['discount_price'] ?? null),
                'price_type' => $this->normalizePriceType($priceTypeRaw),
                'duration_estimate_mins' => (int) ($row['duration_estimate_mins'] ?? 60),
                'is_active' => $this->normalizeBool($row['is_active'] ?? 1),
                'cover_image' => $this->blankToNull($coverImage),
            ];
        }

        if (! empty($errors)) {
            $this->importErrors = $errors;
            return;
        }

        if (empty($validRows)) {
            $this->importErrors = [['row' => '-', 'field' => 'file', 'message' => 'No data rows found in this file.']];
            return;
        }

        $this->importRows = $validRows;
    }

    public function commitServicesImport(): void
    {
        if (empty($this->importRows)) {
            return;
        }

        try {
            DB::transaction(function () {
                foreach ($this->importRows as $row) {
                    $id = $row['id'];
                    unset($row['id']);

                    if ($id) {
                        Service::updateOrCreate(['id' => $id], $row);
                    } else {
                        $row['slug'] = Str::slug($row['name']);
                        Service::create($row);
                    }
                }
            });
        } catch (\Throwable $e) {
            $this->importErrors = [['row' => '-', 'field' => 'commit', 'message' => 'Import failed, nothing was saved: '.$e->getMessage()]];
            return;
        }

        $count = count($this->importRows);
        $this->importMessage = "Imported {$count} ".Str::plural('service', $count).' successfully.';
        $this->importRows = null;
        $this->importFile = null;
    }

    private function blankToNull($value)
    {
        $value = is_string($value) ? trim($value) : $value;
        return ($value === '' || $value === null) ? null : $value;
    }

    private function normalizeBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower(trim((string) $value));
        return ! in_array($value, ['0', 'false', 'no', 'inactive', ''], true);
    }

    /**
     * Glover's `duration` column holds "fixed" | "starts from" (a
     * price-display type, not a time span — see ServicesExport's docblock).
     * "starts from" means the shown price isn't final, closest to our
     * quote_on_inspection. Anything unrecognized (including blank) defaults
     * to fixed rather than failing the row — this is a best-effort mapping,
     * not a strict contract.
     */
    private function normalizePriceType($raw): string
    {
        $value = strtolower(trim((string) $raw));

        return match (true) {
            in_array($value, ['hourly', 'per hour', 'per_hour'], true) => 'hourly',
            in_array($value, ['starts from', 'starts_from', 'quote_on_inspection', 'quote on inspection'], true) => 'quote_on_inspection',
            default => 'fixed',
        };
    }

    public function render()
    {
        $services = Service::with(['category', 'subcategory'])
            ->when($this->categoryId, fn ($q) => $q->where('category_id', $this->categoryId))
            ->when($this->subcategoryId, fn ($q) => $q->where('subcategory_id', $this->subcategoryId))
            ->latest()
            ->paginate(20);

        $categories = ServiceCategory::orderBy('name')->get(['id', 'name']);

        return view('livewire.services.index', compact('services', 'categories'))
            ->layout('layouts.admin', ['title' => 'Services']);
    }
}
