<?php

namespace App\Actions;

use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Models\ParcelSafetyPhoto;
use App\Services\Parcel\ParcelSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Parcel pickup safety (owner decision 2026-10-29): photos are NOT taken on every parcel. When anything looks unusual
 * — the sender, the pickup place, the item, the packing or the pickup time — the rider may photograph the item before
 * packing and the parcel after packing, and the sender is asked to cooperate. Allowed on the rider's own active job,
 * from acceptance until delivery. The file goes to the PRIVATE disk; the order is flagged for admins. The sender is
 * not notified.
 */
class RecordParcelSafetyPhotoAction
{
    public function execute(int $parcelOrderId, FieldWorker $worker, UploadedFile $file, string $stage, array $concerns, ?string $note, ?float $lat, ?float $lng): ParcelSafetyPhoto
    {
        if (! array_key_exists($stage, ParcelSettings::SAFETY_PHOTO_STAGES)) {
            throw new \RuntimeException('Choose what the photo shows.');
        }
        $concerns = array_values(array_unique(array_intersect($concerns, array_keys(ParcelSettings::SAFETY_CONCERNS))));
        if ($concerns === []) {
            throw new \RuntimeException('Tick what looks unusual.');
        }
        if ($lat !== null && ($lat < -90 || $lat > 90 || $lng === null || $lng < -180 || $lng > 180)) {
            $lat = $lng = null;
        }

        $path = null;

        try {
            return DB::transaction(function () use ($parcelOrderId, $worker, $file, $stage, $concerns, $note, $lat, $lng, &$path) {
                $order = ParcelOrder::lockForUpdate()->findOrFail($parcelOrderId);

                if ($order->assigned_worker_id !== $worker->id) {
                    throw new \RuntimeException('This parcel order is not assigned to you.');
                }
                if (! in_array($order->status, ParcelOrder::ACTIVE_RIDER_STATUSES, true)) {
                    throw new \RuntimeException('Photos can only be added while you have this parcel.');
                }
                if ($order->safetyPhotos()->count() >= ParcelSettings::SAFETY_PHOTOS_MAX) {
                    throw new \RuntimeException('This parcel already has '.ParcelSettings::SAFETY_PHOTOS_MAX.' photos.');
                }

                $path = $file->store("parcel-safety/{$order->id}", 'local');
                if (! $path) {
                    throw new \RuntimeException('The photo could not be saved. Try again.');
                }

                $photo = $order->safetyPhotos()->create([
                    'field_worker_id' => $worker->id,
                    'stage' => $stage,
                    'concerns' => $concerns,
                    'note' => ($note = trim((string) $note)) === '' ? null : mb_substr($note, 0, 300),
                    'disk_path' => $path,
                    'lat' => $lat,
                    'lng' => $lng,
                    'taken_at' => now(),
                ]);

                if (! $order->safety_flagged_at) {
                    $order->safety_flagged_at = now();
                    $order->save();
                }

                return $photo;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }

            throw $e;
        }
    }
}
