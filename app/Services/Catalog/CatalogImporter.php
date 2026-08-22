<?php

namespace App\Services\Catalog;

use App\Models\CatalogImportRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Master Catalog Import capability (mission Phase 14 input) — shared engine
 * behind CategoryImporter/SubcategoryImporter/ServiceImporter. Template
 * method: this class owns the pipeline (validate -> classify -> commit ->
 * audit), each subclass supplies its own field rules/relationships via
 * processRow() and generateSlug().
 *
 * The real fix this replaces: the prior import implementation reused a
 * source file's `id` column directly as this table's real primary key
 * (`updateOrCreate(['id' => $id], $row)`), specifically to keep cross-sheet
 * references lined up with Glover's real historical import format. This
 * engine decouples the two via `external_id` (see resolveOwnExternalId()/
 * resolveRelationId()) — the real PK is never dictated by an import file,
 * while cross-sheet references still resolve correctly for both a fresh
 * historical import (whose only identity signal is a bare `id` column) and
 * a round-trip re-export/re-import of 1CallFix's own catalog (which emits
 * both `id` and `external_id`).
 */
abstract class CatalogImporter
{
    public const OUTCOME_CREATED = 'created';
    public const OUTCOME_UPDATED = 'updated';
    public const OUTCOME_UNCHANGED = 'unchanged';
    public const OUTCOME_DEACTIVATED = 'deactivated';
    public const OUTCOME_SKIPPED = 'skipped';
    public const OUTCOME_FAILED = 'failed';

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    abstract public function entityType(): string;

    /**
     * Validate + resolve one raw row (already a plain array from the sheet).
     * Implements VALIDATE, RELATIONSHIP CHECK, PRICE CHECK, IMAGE/URL CHECK
     * for this entity — they're interdependent (e.g. a relationship column
     * must resolve before it's a usable attribute), so one entity-specific
     * method does all four rather than four generic ones that would need to
     * know the entity's shape anyway.
     *
     * @return array{error: array{field: string, message: string}}|array{external_id: ?string, attributes: array, possible_duplicate: bool}
     */
    abstract protected function processRow(array $row): array;

    abstract protected function generateSlug(string $name): string;

    /**
     * STAGE: VALIDATE + RELATIONSHIP CHECK + DUPLICATE CHECK (within this
     * batch) + PRICE CHECK + IMAGE/URL CHECK, then classifies each valid
     * row's outcome against current DB state for the PREVIEW stage.
     *
     * @return array{errors: array, previewRows: array}
     */
    public function validateRows(Collection $rows): array
    {
        $errors = [];
        $previewRows = [];
        $seenExternalIds = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2; // +1 zero-index, +1 header row
            $raw = $row->toArray();

            if ($this->isBlankRow($raw)) {
                $previewRows[] = ['row' => $rowNum, 'external_id' => null, 'name' => null, 'attributes' => [], 'outcome' => self::OUTCOME_SKIPPED, 'existing_id' => null, 'possible_duplicate' => false];
                continue;
            }

            $result = $this->processRow($raw);

            if (isset($result['error'])) {
                $errors[] = ['row' => $rowNum, 'field' => $result['error']['field'], 'message' => $result['error']['message']];
                continue;
            }

            $externalId = $result['external_id'];

            if ($externalId !== null) {
                if (isset($seenExternalIds[$externalId])) {
                    $errors[] = ['row' => $rowNum, 'field' => 'external_id', 'message' => "Duplicate external_id '{$externalId}' also appears at row {$seenExternalIds[$externalId]} in this file."];
                    continue;
                }
                $seenExternalIds[$externalId] = $rowNum;
            }

            $modelClass = $this->modelClass();
            $existing = $externalId !== null ? $modelClass::where('external_id', $externalId)->first() : null;
            $outcome = $this->classifyOutcome($existing, $result['attributes']);

            $previewRows[] = [
                'row' => $rowNum,
                'external_id' => $externalId,
                'name' => $result['attributes']['name'] ?? null,
                'attributes' => $result['attributes'],
                'outcome' => $outcome,
                'existing_id' => $existing?->id,
                'possible_duplicate' => $result['possible_duplicate'] ?? false,
            ];
        }

        if (empty($errors) && empty($previewRows)) {
            $errors[] = ['row' => '-', 'field' => 'file', 'message' => 'No data rows found in this file.'];
        }

        return ['errors' => $errors, 'previewRows' => $previewRows];
    }

    /**
     * STAGE: CONFIRM -> TRANSACTION-SAFE IMPORT -> IMPORT REPORT. Wraps the
     * whole batch in one DB transaction — real rollback-on-partial-failure,
     * not a per-row best-effort. Always persists a CatalogImportRun, even
     * on total failure (status='failed'), so a failed attempt is still a
     * real, auditable record, not silence.
     */
    public function commit(array $previewRows, ?User $actor, ?string $fileName, bool $deactivateMissing): CatalogImportRun
    {
        $startedAt = now();
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0, 'skipped' => 0, 'failed' => 0];
        $results = [];
        $status = 'completed';

        try {
            DB::transaction(function () use ($previewRows, $deactivateMissing, &$counts, &$results) {
                $modelClass = $this->modelClass();
                $seenExternalIds = [];

                foreach ($previewRows as $row) {
                    if ($row['outcome'] === self::OUTCOME_SKIPPED) {
                        $counts['skipped']++;
                        continue;
                    }

                    $attributes = $row['attributes'];
                    if ($row['external_id'] !== null) {
                        $attributes['external_id'] = $row['external_id'];
                        $seenExternalIds[] = $row['external_id'];
                    }

                    if ($row['existing_id']) {
                        if ($row['outcome'] === self::OUTCOME_UNCHANGED) {
                            $counts['unchanged']++;
                        } else {
                            $modelClass::find($row['existing_id'])->update($attributes);
                            $counts['updated']++;
                        }
                    } else {
                        $attributes['slug'] = $this->generateSlug($attributes['name']);
                        $modelClass::create($attributes);
                        $counts['created']++;
                    }

                    $results[] = ['row' => $row['row'], 'external_id' => $row['external_id'], 'name' => $row['name'], 'outcome' => $row['outcome']];
                }

                // Only rows THIS batch actually sourced (i.e. carried an
                // external_id) are eligible to be deactivated, and only when
                // the batch produced at least one real external_id -- an
                // empty $seenExternalIds with whereNotIn() would otherwise
                // match (and deactivate) every externally-sourced row,
                // which is exactly the "blindly delete/deactivate" this
                // capability must never do.
                if ($deactivateMissing && ! empty($seenExternalIds)) {
                    $toDeactivate = $modelClass::whereNotNull('external_id')
                        ->whereNotIn('external_id', $seenExternalIds)
                        ->where('is_active', true)
                        ->get();

                    foreach ($toDeactivate as $model) {
                        $model->update(['is_active' => false]);
                        $counts['deactivated']++;
                        $results[] = ['row' => null, 'external_id' => $model->external_id, 'name' => $model->name, 'outcome' => self::OUTCOME_DEACTIVATED];
                    }
                }
            });
        } catch (\Throwable $e) {
            $status = 'failed';
            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0, 'skipped' => 0, 'failed' => count($previewRows)];
            $results = [['row' => '-', 'external_id' => null, 'name' => null, 'outcome' => self::OUTCOME_FAILED, 'message' => $e->getMessage()]];
        }

        return CatalogImportRun::create([
            'entity_type' => $this->entityType(),
            'initiated_by' => $actor?->id,
            'file_name' => $fileName,
            'deactivate_missing' => $deactivateMissing,
            'status' => $status,
            'created_count' => $counts['created'],
            'updated_count' => $counts['updated'],
            'unchanged_count' => $counts['unchanged'],
            'deactivated_count' => $counts['deactivated'],
            'skipped_count' => $counts['skipped'],
            'failed_count' => $counts['failed'],
            'results' => $results,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
    }

    // ============================== Shared resolution helpers ==============================

    /**
     * A row's own external_id: an explicit `external_id` column wins;
     * otherwise a bare `id` column (a raw historical/source sheet has no
     * external_id column at all) becomes the external_id. This is the one
     * rule that keeps a source file's identity out of our real PK entirely.
     */
    protected function resolveOwnExternalId(array $row): ?string
    {
        $explicit = $this->blankToNull($row['external_id'] ?? null);
        if ($explicit !== null) {
            return (string) $explicit;
        }

        $idCell = $this->blankToNull($row['id'] ?? null);

        return $idCell !== null ? (string) $idCell : null;
    }

    /**
     * A relationship column (e.g. subcategories.category_id) may hold either
     * a source system's id (fresh historical import — resolve via the
     * related row's external_id) or 1CallFix's own real id (a re-imported
     * export of our own catalog, or an admin typing a real id they looked
     * up) — try external_id first, then fall back to a literal real-id
     * match. Never assumes which one it is.
     *
     * @param  class-string<Model>  $relatedModelClass
     */
    protected function resolveRelationId(string $relatedModelClass, $rawValue): ?int
    {
        $rawValue = $this->blankToNull($rawValue);
        if ($rawValue === null) {
            return null;
        }

        $byExternalId = $relatedModelClass::where('external_id', (string) $rawValue)->first();
        if ($byExternalId) {
            return $byExternalId->id;
        }

        $byRealId = $relatedModelClass::where('id', $rawValue)->first();

        return $byRealId?->id;
    }

    protected function classifyOutcome(?Model $existing, array $attributes): string
    {
        if (! $existing) {
            return self::OUTCOME_CREATED;
        }

        foreach ($attributes as $key => $value) {
            if (! $this->valuesEqual($existing->getAttribute($key), $value)) {
                return self::OUTCOME_UPDATED;
            }
        }

        return self::OUTCOME_UNCHANGED;
    }

    protected function valuesEqual($existing, $new): bool
    {
        // Export/Import session — ProductImporter's `images` attribute is
        // the first array-valued column any CatalogImporter subclass has
        // written (Categories/Subcategories/Services are all scalar
        // columns). Without this branch, PHP's (string) cast on an array
        // silently becomes the literal "Array" (with a warning) for BOTH
        // sides, so any two different image sets would always compare
        // "equal" — classifyOutcome() would wrongly report OUTCOME_UNCHANGED
        // and commit() would then skip the actual update entirely.
        if (is_array($existing) || is_array($new)) {
            return json_encode((array) $existing) === json_encode((array) $new);
        }

        if (is_bool($existing) || is_bool($new)) {
            return (bool) $existing === $this->normalizeBool($new);
        }

        if (is_numeric($existing) && is_numeric($new)) {
            return abs((float) $existing - (float) $new) < 0.0001;
        }

        return (string) $existing === (string) $new;
    }

    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->blankToNull($value) !== null) {
                return false;
            }
        }

        return true;
    }

    protected function blankToNull($value)
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === '' || $value === null) ? null : $value;
    }

    protected function normalizeBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        return ! in_array($value, ['0', 'false', 'no', 'inactive', ''], true);
    }

    /**
     * IMAGE/URL CHECK. A CDN URL (http/https/protocol-relative, matching
     * ServiceCategory/Service's own dual-source getImageUrlAttribute()
     * convention) or a bare local relative path ending in a real image
     * extension — never a scheme this app doesn't render (data:,
     * javascript:, etc.), even though the display accessor tolerates
     * `data:` defensively for manually-pasted values; import time is
     * stricter on purpose.
     */
    protected function validateImageReference($value): bool
    {
        if ($value === null) {
            return true;
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            return (bool) filter_var($value, FILTER_VALIDATE_URL);
        }

        if (Str::startsWith($value, '//')) {
            return (bool) filter_var('https:'.$value, FILTER_VALIDATE_URL);
        }

        return (bool) preg_match('/^[\w\-\/. ]+\.(png|jpe?g|gif|webp)$/i', $value);
    }
}
