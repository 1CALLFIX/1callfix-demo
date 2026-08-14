<?php

namespace App\Services\Kyc;

use App\Models\KycVerificationVideo;
use App\Models\Provider;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Partner KYC verification video (resolved business decision -- see
 * KNOWN_RISKS_AND_DECISIONS.md). Private storage, authorization-gated
 * retrieval only (KycDocumentController) -- never a public profile asset.
 * Submitting a video does NOT auto-approve KYC by itself; see
 * ReviewProviderKycAction's own gate (documents + video + other checks).
 */
class KycVerificationVideoService
{
    private const ALLOWED_MIME_TO_EXTENSION = [
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
    ];

    public function submit(
        Provider $provider,
        UploadedFile $file,
        User $actor,
        ?string $challengePhrase = null,
        string $uploadSource = 'self',
        ?User $franchiseStaff = null,
        array $scope = [],
    ): KycVerificationVideo {
        if (! $file->isValid() || ! array_key_exists($file->getMimeType(), self::ALLOWED_MIME_TO_EXTENSION)) {
            throw new \RuntimeException('Unsupported video file type. Only MP4, MOV, and WEBM are accepted.');
        }

        $maxMb = (float) Setting::get('kyc.max_video_size_mb', '50', $scope);
        if ($file->getSize() > $maxMb * 1024 * 1024) {
            throw new \RuntimeException("Video exceeds the maximum allowed size of {$maxMb}MB.");
        }

        $extension = self::ALLOWED_MIME_TO_EXTENSION[$file->getMimeType()];
        $path = "kyc/providers/{$provider->id}/verification-video/".Str::uuid().'.'.$extension;

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        $video = KycVerificationVideo::create([
            'provider_id' => $provider->id,
            'disk_path' => $path,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'challenge_phrase' => $challengePhrase,
            'status' => 'submitted',
            'uploaded_by' => $actor->id,
            'upload_source' => $uploadSource,
            'franchise_staff_id' => $franchiseStaff?->id,
        ]);

        $provider->update(['kyc_video_status' => 'submitted']);

        return $video;
    }

    public function approve(KycVerificationVideo $video, User $reviewer): KycVerificationVideo
    {
        $video->update(['status' => 'approved', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
        $video->provider->update(['kyc_video_status' => 'approved']);

        return $video->fresh();
    }

    public function reject(KycVerificationVideo $video, User $reviewer, string $reason): KycVerificationVideo
    {
        $video->update(['status' => 'rejected', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'rejection_reason' => $reason]);
        $video->provider->update(['kyc_video_status' => 'rejected']);

        return $video->fresh();
    }
}
