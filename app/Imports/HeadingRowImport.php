<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Deliberately dumb — just reads the sheet into a Collection keyed by
 * normalized header names (Laravel Excel lowercases + underscores headers
 * automatically, so Glover's "vendor_Type_id" reads back as "vendor_type_id").
 * All validation and upsert logic lives in the Livewire components, not here,
 * so each entity's rules/mapping stay easy to read in one place rather than
 * split across three near-identical Import classes.
 */
class HeadingRowImport implements ToCollection, WithHeadingRow
{
    public Collection $rows;

    public function collection(Collection $rows): void
    {
        $this->rows = $rows;
    }
}
