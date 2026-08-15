<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per catalog import commit (Categories/Subcategories/Services) —
 * see App\Services\Catalog\CatalogImporter. Real, queryable "Import Report":
 * per-outcome counts for fast display, plus a full per-row `results` JSON
 * (row number, external_id, name, outcome, message) for real auditability.
 */
class CatalogImportRun extends Model
{
    protected $table = 'catalog_import_runs';

    protected $fillable = [
        'entity_type', 'initiated_by', 'file_name', 'deactivate_missing', 'status',
        'created_count', 'updated_count', 'unchanged_count', 'deactivated_count', 'skipped_count', 'failed_count',
        'results', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'deactivate_missing' => 'boolean',
        'results' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function initiatedBy() { return $this->belongsTo(User::class, 'initiated_by'); }
}
