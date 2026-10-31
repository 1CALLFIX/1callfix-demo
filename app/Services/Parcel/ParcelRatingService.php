<?php

namespace App\Services\Parcel;

use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Models\ParcelRating;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Parcel Stage 2 — the sender rates the rider (1-5, optional comment) once the parcel is delivered. One rating per
 * parcel; the rider's rating_avg is recalculated from all their parcel ratings.
 */
class ParcelRatingService
{
    public function cannotRate(ParcelOrder $order, int $customerId): ?string
    {
        if ($order->customer_id !== $customerId) {
            return 'This is not your parcel.';
        }
        if ($order->status !== 'delivered' || ! $order->assigned_worker_id) {
            return 'You can rate the rider once the parcel is delivered.';
        }
        if (ParcelRating::where('parcel_order_id', $order->id)->exists()) {
            return 'You have already rated this delivery.';
        }

        return null;
    }

    public function rate(ParcelOrder $order, User $customer, int $rating, ?string $comment = null): ParcelRating
    {
        if (($why = $this->cannotRate($order, $customer->id)) !== null) {
            throw new \RuntimeException($why);
        }
        if ($rating < 1 || $rating > 5) {
            throw new \RuntimeException('Choose 1 to 5 stars.');
        }
        $comment = $comment !== null ? trim($comment) : null;
        if ($comment !== null && mb_strlen($comment) > 500) {
            throw new \RuntimeException('Keep the comment under 500 characters.');
        }

        $row = DB::transaction(function () use ($order, $customer, $rating, $comment) {
            $row = ParcelRating::create([
                'parcel_order_id' => $order->id, 'customer_id' => $customer->id, 'field_worker_id' => $order->assigned_worker_id,
                'rating' => $rating, 'comment' => $comment !== '' ? $comment : null,
            ]);
            $avg = (float) ParcelRating::where('field_worker_id', $order->assigned_worker_id)->avg('rating');
            FieldWorker::whereKey($order->assigned_worker_id)->update(['rating_avg' => round($avg, 2)]);

            return $row;
        });
        ActivityLogger::logModel($customer, $row, "Parcel rider rated {$rating}/5", ['order' => $order->code]);

        return $row;
    }
}
