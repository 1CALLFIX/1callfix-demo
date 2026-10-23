<?php

namespace App\Console\Commands;

use App\Actions\AdminCancelParcelOrderAction;
use App\Models\ParcelOrder;
use App\Services\Parcel\ParcelSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cancels online parcel orders whose payment never arrived within parcel.unpaid_expiry_minutes (per order's
 * own location; default 30). No rider was ever offered such an order, so nothing else is affected and no fee
 * applies. A payment captured later is refunded by ParcelPaymentService::markPaid().
 */
class ExpireUnpaidParcelOrders extends Command
{
    protected $signature = 'parcel:expire-unpaid';

    protected $description = 'Cancels online parcel orders not paid within parcel.unpaid_expiry_minutes';

    public function handle(AdminCancelParcelOrderAction $cancel): int
    {
        $expired = 0;

        ParcelOrder::with('franchise')
            ->where('status', 'pending')
            ->where('payment_method', 'online')
            ->where('payment_status', 'pending')
            ->where('created_at', '<=', now()->subMinutes(5)) // the smallest allowed setting
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($cancel, &$expired) {
                foreach ($orders as $order) {
                    $minutes = (int) ParcelSettings::get('parcel.unpaid_expiry_minutes', array_filter([
                        'zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id,
                        'city_id' => $order->franchise?->city_id, 'country_id' => $order->franchise?->country_id,
                    ]));

                    if ($order->created_at->gt(now()->subMinutes($minutes))) {
                        continue;
                    }

                    try {
                        $cancel->execute($order->id, "Payment not received within {$minutes} minutes", 'system');
                        $expired++;
                    } catch (\Throwable $e) {
                        Log::warning("parcel:expire-unpaid could not cancel parcel order [{$order->id}]: ".$e->getMessage());
                    }
                }
            });

        $this->info("Parcel unpaid sweep: {$expired} cancelled.");

        return self::SUCCESS;
    }
}
