<?php

namespace App\Services\Kyc;

use App\Models\FieldWorker;
use App\Models\FieldWorkerDocument;
use App\Models\KycDocumentRequirement;
use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Document security overhaul (mission Phase 2). Every upload is validated
 * (MIME + extension whitelist, size cap), stored on the PRIVATE disk under
 * a generated, non-guessable filename (never the original filename — closes
 * the path-traversal/disguised-executable surface at the storage-path
 * level, on top of the MIME/extension whitelist), and creates a NEW row
 * rather than overwriting one — is_current flags which row is the live
 * submission for its type, so resubmission never erases history.
 */
class KycDocumentService
{
    /** Whitelist, not a blacklist -- executables/scripts are never reachable regardless of what a client claims its MIME type is. */
    private const ALLOWED_MIME_TO_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    /**
     * @param  Provider|FieldWorker  $subject
     */
    public function upload(
        Provider|FieldWorker $subject,
        string $documentType,
        UploadedFile $file,
        User $actor,
        string $uploadSource = 'self',
        ?User $franchiseStaff = null,
        array $scope = [],
    ): ProviderDocument|FieldWorkerDocument {
        $this->assertValidFile($file, $scope);

        $extension = self::ALLOWED_MIME_TO_EXTENSION[$file->getMimeType()] ?? null;
        if (! $extension) {
            throw new \RuntimeException('Unsupported document file type. Only JPG, PNG, and PDF are accepted.');
        }

        $modelClass = $subject instanceof Provider ? ProviderDocument::class : FieldWorkerDocument::class;
        $ownerColumn = $subject instanceof Provider ? 'provider_id' : 'field_worker_id';
        $folder = $subject instanceof Provider ? 'providers' : 'field-workers';

        $filename = Str::uuid().'.'.$extension;
        $path = "kyc/{$folder}/{$subject->id}/{$documentType}/{$filename}";

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        // Supersede, never delete — the previous current row for this exact
        // type stays in the table, just no longer "current".
        $modelClass::where($ownerColumn, $subject->id)->where('type', $documentType)->where('is_current', true)
            ->update(['is_current' => false]);

        return $modelClass::create([
            $ownerColumn => $subject->id,
            'type' => $documentType,
            'disk_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'status' => 'pending',
            'is_current' => true,
            'uploaded_by' => $actor->id,
            'upload_source' => $uploadSource,
            'franchise_staff_id' => $franchiseStaff?->id,
        ]);
    }

    private function assertValidFile(UploadedFile $file, array $scope): void
    {
        if (! $file->isValid()) {
            throw new \RuntimeException('The uploaded file could not be read.');
        }

        if (! array_key_exists($file->getMimeType(), self::ALLOWED_MIME_TO_EXTENSION)) {
            throw new \RuntimeException('Unsupported document file type. Only JPG, PNG, and PDF are accepted.');
        }

        // Extension check too, not just MIME -- a MIME sniff can be spoofed
        // in some environments, so both must independently agree.
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = array_unique(array_values(self::ALLOWED_MIME_TO_EXTENSION));
        if (! in_array($extension, $allowedExtensions, true)) {
            throw new \RuntimeException('Unsupported file extension.');
        }

        // A pure technical/security safety cap, not a commercial decision —
        // admin-tunable, defaulted to a conservative, honest engineering
        // value (10MB) rather than an invented business number.
        $maxMb = (float) Setting::get('kyc.max_document_size_mb', '10', $scope);
        if ($file->getSize() > $maxMb * 1024 * 1024) {
            throw new \RuntimeException("File exceeds the maximum allowed size of {$maxMb}MB.");
        }
    }

    /** Merges global (country_id null) requirements with country-specific overrides of the same document_type. */
    public function requirementsFor(string $applicableType, ?int $countryId): \Illuminate\Support\Collection
    {
        $global = KycDocumentRequirement::where('applicable_type', $applicableType)
            ->whereNull('country_id')->where('is_active', true)->get()->keyBy('document_type');

        if ($countryId) {
            $overrides = KycDocumentRequirement::where('applicable_type', $applicableType)
                ->where('country_id', $countryId)->where('is_active', true)->get()->keyBy('document_type');

            foreach ($overrides as $type => $row) {
                $global->put($type, $row);
            }
        }

        return $global->sortBy('sort_order')->values();
    }

    /** @param  Provider|FieldWorker  $subject */
    public function missingRequiredForSubmission(Provider|FieldWorker $subject, ?int $countryId = null): array
    {
        $applicableType = $subject instanceof Provider ? 'provider' : 'field_worker';
        $required = $this->requirementsFor($applicableType, $countryId)->where('is_required', true)->pluck('document_type');
        $submittedTypes = $subject->currentDocuments()->where('status', '!=', 'rejected')->pluck('type');

        return $required->diff($submittedTypes)->values()->all();
    }

    /** @param  Provider|FieldWorker  $subject */
    public function missingApprovedRequirements(Provider|FieldWorker $subject, ?int $countryId = null): array
    {
        $applicableType = $subject instanceof Provider ? 'provider' : 'field_worker';
        $required = $this->requirementsFor($applicableType, $countryId)->where('is_required', true)->pluck('document_type');
        $approvedTypes = $subject->currentDocuments()->where('status', 'approved')->pluck('type');

        return $required->diff($approvedTypes)->values()->all();
    }
}
