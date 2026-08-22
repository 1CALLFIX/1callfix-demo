<?php

namespace App\Services\Onboarding;

use App\Models\CatalogImportRun;
use App\Models\Franchise;
use App\Models\Provider;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Export Everywhere + Import Where It's Safe session, Part 3 — Bulk
 * Pre-Register for Providers. See CustomerPreRegisterImporter's docblock
 * for why this is a standalone pipeline, not a CatalogImporter subclass.
 *
 * There is no real provider self-signup flow anywhere in this codebase
 * (confirmed by audit — providers are KYC-gated onboarding, created only
 * by an admin action; see AuthController::verifyOtp()'s own docblock).
 * This importer creates the User + Provider pair with the SAME real
 * columns/relationship a genuine provider record has — critically,
 * `kyc_status` is left at the `providers` table's own column default
 * ('pending', the real "awaiting KYC" state DispatchService's candidate
 * queries already filter out — see DispatchService::where('kyc_status',
 * 'approved')), never forced to 'approved' by this importer under any
 * circumstance. `kyc_deadline_at` is set to the documented 30-day policy
 * (see 2026_08_14_016000_add_kyc_lifecycle_columns_to_providers_table's
 * own comment) since nothing else in this codebase currently sets it for
 * a freshly-created provider — closing that real gap rather than
 * reproducing it.
 *
 * Franchise-scoped: like ProductImporter, $actor is threaded through the
 * constructor so a franchise-scoped actor can only pre-register providers
 * into their OWN franchise, never another one.
 */
class ProviderPreRegisterImporter
{
    public const OUTCOME_CREATED = 'created';
    public const OUTCOME_SKIPPED_EXISTING = 'skipped_existing';
    public const OUTCOME_SKIPPED_BLANK = 'skipped_blank';

    public function __construct(private ?User $actor = null)
    {
    }

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
                $previewRows[] = ['row' => $rowNum, 'name' => null, 'phone' => null, 'outcome' => self::OUTCOME_SKIPPED_BLANK];
                continue;
            }

            $name = $this->blankToNull($raw['name'] ?? null);
            if ($name === null || ! is_string($name) || mb_strlen($name) > 255) {
                $errors[] = ['row' => $rowNum, 'field' => 'name', 'message' => 'The name field is required and must be 255 characters or fewer.'];
                continue;
            }

            // See CustomerPreRegisterImporter's identical comment — a
            // spreadsheet/CSV reader infers numeric types from a phone
            // column's content regardless of quoting.
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

            $franchiseRaw = $this->blankToNull($raw['franchise_id'] ?? null);
            if ($franchiseRaw === null) {
                $errors[] = ['row' => $rowNum, 'field' => 'franchise_id', 'message' => 'The franchise_id field is required.'];
                continue;
            }
            $franchise = Franchise::find($franchiseRaw);
            if (! $franchise) {
                $errors[] = ['row' => $rowNum, 'field' => 'franchise_id', 'message' => "franchise_id {$franchiseRaw} does not exist."];
                continue;
            }

            if ($this->actor && ! $this->actor->hasPermission('providers.manage', [
                'zone_id' => null, 'franchise_id' => $franchise->id,
                'city_id' => $franchise->city_id, 'country_id' => $franchise->country_id,
            ])) {
                $errors[] = ['row' => $rowNum, 'field' => 'franchise_id', 'message' => "You do not have permission to pre-register providers into franchise_id {$franchiseRaw} (outside your assigned scope)."];
                continue;
            }

            $zoneId = null;
            $zoneRaw = $this->blankToNull($raw['zone_id'] ?? null);
            if ($zoneRaw !== null) {
                $zone = Zone::where('id', $zoneRaw)->where('franchise_id', $franchise->id)->first();
                if (! $zone) {
                    $errors[] = ['row' => $rowNum, 'field' => 'zone_id', 'message' => "zone_id {$zoneRaw} does not exist or does not belong to franchise_id {$franchiseRaw}."];
                    continue;
                }
                $zoneId = $zone->id;
            }

            $existingUser = User::where('phone', $phone)->first();
            if ($existingUser && $existingUser->providerProfile !== null) {
                $previewRows[] = [
                    'row' => $rowNum, 'name' => $name, 'phone' => $phone,
                    'franchise_id' => $franchise->id, 'zone_id' => $zoneId,
                    'outcome' => self::OUTCOME_SKIPPED_EXISTING, 'existing_id' => $existingUser->providerProfile->id,
                ];
                continue;
            }
            if ($existingUser && $existingUser->role !== 'provider') {
                $errors[] = ['row' => $rowNum, 'field' => 'phone', 'message' => "Phone '{$phone}' is already registered as a {$existingUser->role}, not a provider."];
                continue;
            }

            $previewRows[] = [
                'row' => $rowNum,
                'name' => $name,
                'phone' => $phone,
                'franchise_id' => $franchise->id,
                'zone_id' => $zoneId,
                'outcome' => self::OUTCOME_CREATED,
                'existing_id' => null,
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

                    $user = User::create([
                        'uuid' => (string) Str::uuid(),
                        'name' => $row['name'],
                        'phone' => $row['phone'],
                        'role' => 'provider',
                        'status' => 'active',
                        'franchise_id' => $row['franchise_id'],
                        'zone_id' => $row['zone_id'],
                        'preferred_language' => 'en',
                    ]);

                    // kyc_status deliberately NOT set here — the providers
                    // table's own column default ('pending') applies, same
                    // as every other column this importer doesn't touch.
                    // See class docblock for kyc_deadline_at's own reasoning.
                    Provider::create([
                        'user_id' => $user->id,
                        'franchise_id' => $row['franchise_id'],
                        'zone_id' => $row['zone_id'],
                        'provider_type' => 'independent',
                        'kyc_deadline_at' => now()->addDays(30),
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
            'entity_type' => 'providers_prereg',
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
