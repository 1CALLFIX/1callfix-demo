<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FieldWorkerDocument;
use App\Models\KycVerificationVideo;
use App\Models\ProviderDocument;
use Illuminate\Support\Facades\Storage;

/**
 * The ONLY way a KYC document/video is ever retrieved — streamed from the
 * PRIVATE disk after an authorization check, never a raw public URL or
 * exposed disk path (closes the exact gap the mission called out: the
 * admin views previously rendered `<a href="{{ $doc->file_url }}">`
 * directly). 404s (not 403s) on an authorization failure — same
 * information-hiding convention as Bookings\Show::mount() elsewhere in
 * this codebase, so a document ID that exists but isn't yours doesn't even
 * confirm its own existence.
 */
class KycDocumentController extends Controller
{
    public function providerDocument(int $documentId)
    {
        $document = ProviderDocument::with('provider')->find($documentId);
        abort_if(! $document, 404);
        $this->authorizeAgainst($document->provider->franchise_id, $document->provider->zone_id, 'providers.review_kyc');

        return $this->stream($document->disk_path, $document->original_filename);
    }

    public function fieldWorkerDocument(int $documentId)
    {
        $document = FieldWorkerDocument::with('fieldWorker')->find($documentId);
        abort_if(! $document, 404);
        $this->authorizeAgainst($document->fieldWorker->franchise_id, $document->fieldWorker->zone_id, 'workers.review_kyc');

        return $this->stream($document->disk_path, $document->original_filename);
    }

    public function verificationVideo(int $videoId)
    {
        $video = KycVerificationVideo::with('provider')->find($videoId);
        abort_if(! $video, 404);
        $this->authorizeAgainst($video->provider->franchise_id, $video->provider->zone_id, 'providers.review_kyc');

        return $this->stream($video->disk_path, 'verification-video');
    }

    private function authorizeAgainst(?int $franchiseId, ?int $zoneId, string $permission): void
    {
        $allowed = auth()->user()->hasPermission($permission, array_filter([
            'zone_id' => $zoneId,
            'franchise_id' => $franchiseId,
        ]));

        abort_if(! $allowed, 404);
    }

    private function stream(?string $diskPath, ?string $displayName)
    {
        abort_if(! $diskPath || ! Storage::disk('local')->exists($diskPath), 404);

        return Storage::disk('local')->response($diskPath, $displayName, ['Content-Disposition' => 'inline']);
    }
}
