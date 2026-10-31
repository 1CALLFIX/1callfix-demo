<?php

namespace App\Console\Commands;

use App\Models\ParcelOrder;
use App\Models\ParcelSafetyPhoto;
use App\Services\Parcel\ParcelSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Parcel Stage 2 — deletes rider safety photos once the parcel has been finished for
 * `parcel.safety_photo_keep_days` days. Unset = keep them (does nothing). A photo on an order with a protection
 * claim still being decided is kept.
 */
class PurgeParcelSafetyPhotos extends Command
{
    protected $signature = 'parcel:purge-safety-photos';

    protected $description = 'Delete parcel safety photos older than the keep-days setting';

    public function handle(): int
    {
        $days = ParcelSettings::get('parcel.safety_photo_keep_days');
        if ($days === null || (int) $days < 1) {
            $this->info('parcel.safety_photo_keep_days is not set; nothing deleted.');

            return self::SUCCESS;
        }
        $cutoff = now()->subDays((int) $days);

        $orders = ParcelOrder::query()
            ->whereIn('status', ['delivered', 'cancelled'])
            ->where('updated_at', '<', $cutoff)
            ->whereHas('safetyPhotos')
            ->whereDoesntHave('protectionClaims', fn ($q) => $q->whereIn('status', ['requested', 'accepted']))
            ->pluck('id');

        $deleted = 0;
        foreach ($orders->chunk(200) as $chunk) {
            ParcelSafetyPhoto::whereIn('parcel_order_id', $chunk)->get()->each(function (ParcelSafetyPhoto $photo) use (&$deleted) {
                if ($photo->disk_path) {
                    Storage::disk('local')->delete($photo->disk_path);
                }
                $photo->delete();
                $deleted++;
            });
        }

        $this->info("Deleted {$deleted} safety photo(s).");

        return self::SUCCESS;
    }
}
