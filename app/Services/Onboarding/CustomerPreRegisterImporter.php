<?php

namespace App\Services\Onboarding;

use App\Models\CatalogImportRun;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Export Everywhere + Import Where It's Safe session, Part 3 — Bulk
 * Pre-Register for Customers. Deliberately NOT a CatalogImporter subclass
 * (that engine's external_id-based dedup/deactivate-missing shape fits a
 * flat catalog row, not "create an account shell using the real signup
 * model" here) — a smaller, purpose-built validate -> preview -> commit
 * pipeline with the same never-blind-import discipline.
 *
 * Every row this creates is IDENTICAL in shape to AuthController::
 * resolveCustomer()'s own real first-login signup — same fields, same
 * User::create() call shape — deliberately never sets phone_verified_at
 * (the real signup path doesn't either; this app has no separate
 * phone-verification gate beyond "does a valid Sanctum token exist", and
 * a token is only ever issued through the real OTP verify flow — see
 * AuthController::verifyOtp()). A pre-registered row here and a row
 * created by a customer's own first real OTP login are the exact same
 * shape; the ONLY thing distinguishing "pre-registered, never logged in"
 * from "verified" is whether that real OTP flow has happened yet — there
 * is no boolean this importer could set to fake that.
 */
class CustomerPreRegisterImporter
{
    public const OUTCOME_CREATED = 'created';
    public const OUTCOME_SKIPPED_EXISTING = 'skipped_existing';
    public const OUTCOME_SKIPPED_BLANK = 'skipped_blank';

    /**
     * @return array{errors: array, previewRows: array}
     */
    public function validateRows(Collection $rows): array
    {
        $errors = [];
        $previewRows = [];
        $seenPhones = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $raw = $row->toArray();

            if ($this->isBlankRow($raw)) {
                $previewRows[] = ['row' => $rowNum, 'name' => null, 'phone' => null, 'outcome' => self::OUTCOME_SKIPPED_BLANK, 'reason' => null];
                continue;
            }

            $name = $this->blankToNull($raw['name'] ?? null);
            if ($name === null || ! is_string($name) || mb_strlen($name) > 255) {
                $errors[] = ['row' => $rowNum, 'field' => 'name', 'message' => 'The name field is required and must be 255 characters or fewer.'];
                continue;
            }

            // A spreadsheet/CSV reader (Maatwebsite/PhpSpreadsheet) infers
            // numeric TYPES from a cell's content regardless of quoting —
            // a phone number column reads back as a PHP int/float, not a
            // string. Cast before validating length, same as
            // resolveOwnExternalId()/resolveRelationId() already do for
            // their own id-shaped columns in CatalogImporter.
            $phoneRaw = $this->blankToNull($raw['phone'] ?? null);
            if ($phoneRaw === null || mb_strlen((string) $phoneRaw) > 20) {
                $errors[] = ['row' => $rowNum, 'field' => 'phone', 'message' => 'The phone field is required and must be 20 characters or fewer.'];
                continue;
            }
            $phone = trim((string) $phoneRaw);

            if (isset($seenPhones[$phone])) {
                $errors[] = ['row' => $rowNum, 'field' => 'phone', 'message' => "Phone '{$phone}' also appears at row {$seenPhones[$phone]} in this file."];
                continue;
            }
            $seenPhones[$phone] = $rowNum;

            $existing = User::where('phone', $phone)->first();

            if ($existing && $existing->role !== 'customer') {
                $errors[] = ['row' => $rowNum, 'field' => 'phone', 'message' => "Phone '{$phone}' is already registered as a {$existing->role}, not a customer."];
                continue;
            }

            $email = $this->blankToNull($raw['email'] ?? null);

            $previewRows[] = [
                'row' => $rowNum,
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'outcome' => $existing ? self::OUTCOME_SKIPPED_EXISTING : self::OUTCOME_CREATED,
                'existing_id' => $existing?->id,
            ];
        }

        if (empty($errors) && empty($previewRows)) {
            $errors[] = ['row' => '-', 'field' => 'file', 'message' => 'No data rows found in this file.'];
        }

        return ['errors' => $errors, 'previewRows' => $previewRows];
    }

    public function commit(array $previewRows, ?User $actor, ?string $fileName): CatalogImportRun
    {
        $startedAt = now();
        $counts = ['created' => 0, 'skipped' => 0];
        $results = [];
        $status = 'completed';

        try {
            DB::transaction(function () use ($previewRows, &$counts, &$results) {
                foreach ($previewRows as $row) {
                    if ($row['outcome'] !== self::OUTCOME_CREATED) {
                        $counts['skipped']++;
                        $results[] = ['row' => $row['row'], 'external_id' => $row['phone'] ?? null, 'name' => $row['name'] ?? null, 'outcome' => $row['outcome']];
                        continue;
                    }

                    // Same shape as AuthController::resolveCustomer()'s real
                    // signup User::create() call — see class docblock.
                    User::create([
                        'uuid' => (string) Str::uuid(),
                        'name' => $row['name'],
                        'phone' => $row['phone'],
                        'email' => $row['email'] ?? null,
                        'role' => 'customer',
                        'status' => 'active',
                        'preferred_language' => 'en',
                    ]);

                    $counts['created']++;
                    $results[] = ['row' => $row['row'], 'external_id' => $row['phone'], 'name' => $row['name'], 'outcome' => self::OUTCOME_CREATED];
                }
            });
        } catch (\Throwable $e) {
            $status = 'failed';
            $counts = ['created' => 0, 'skipped' => count($previewRows)];
            $results = [['row' => '-', 'external_id' => null, 'name' => null, 'outcome' => 'failed', 'message' => $e->getMessage()]];
        }

        return CatalogImportRun::create([
            'entity_type' => 'customers_prereg',
            'initiated_by' => $actor?->id,
            'file_name' => $fileName,
            'deactivate_missing' => false,
            'status' => $status,
            'created_count' => $counts['created'],
            'updated_count' => 0,
            'unchanged_count' => 0,
            'deactivated_count' => 0,
            'skipped_count' => $counts['skipped'],
            'failed_count' => $status === 'failed' ? count($previewRows) : 0,
            'results' => $results,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
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

    private function blankToNull($value)
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === '' || $value === null) ? null : $value;
    }
}
