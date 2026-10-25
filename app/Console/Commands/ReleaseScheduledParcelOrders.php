<?php

namespace App\Console\Commands;

use App\Actions\CreateParcelOrderAction;
use App\Jobs\ParcelDispatchJob;
use App\Models\ParcelOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Parcel Stage 1 (2026-10-25): offers scheduled parcels to riders once their pickup time is within
 * parcel.schedule_dispatch_lead_minutes (per order's own location; default 30). Only paid orders, or cash
 * orders, are released; an unpaid online order is left to parcel:expire-unpaid. Each order is claimed with a
 * conditional update (CreateParcelOrderAction::claimRelease), so a second run never starts a second search.
 */
class ReleaseScheduledParcelOrders extends Command
{
    protected $signature = 'parcel:release-scheduled';

    protected $description = 'Offers scheduled parcel orders to riders shortly before their pickup time';

    public function handle(): int
    {
        $released = 0;

        ParcelOrder::with('franchise')
            ->where('status', 'pending')
            ->where('delivery_type', 'scheduled')
            ->whereNull('dispatch_released_at')
            ->where(fn ($q) => $q->where('payment_method', 'cash')->orWhere('payment_status', 'paid'))
            ->where('scheduled_at', '<=', now()->addMinutes(240)) // the largest allowed lead setting
            ->orderBy('scheduled_at')
            ->chunkById(100, function ($orders) use (&$released) {
                foreach ($orders as $order) {
                    if (CreateParcelOrderAction::heldForSchedule($order)) {
                        continue;
                    }

                    try {
                        if (CreateParcelOrderAction::claimRelease($order)) {
                            ParcelDispatchJob::dispatch($order->id);
                            $released++;
                        }
                    } catch (\Throwable $e) {
                        Log::warning("parcel:release-scheduled could not release parcel order [{$order->id}]: ".$e->getMessage());
                    }
                }
            });

        $this->info("Parcel scheduled release: {$released} offered to riders.");

        return self::SUCCESS;
    }
}
