<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingCompensation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customer-initiated tips — the one compensation type funded by the
 * CUSTOMER, not the platform/franchise. Real money movement: debits the
 * customer's wallet, credits the provider's wallet, atomically (both must
 * succeed together — WalletService::debit() already refuses to go
 * negative, so an under-funded customer simply can't tip more than their
 * balance, same guarantee every other wallet debit in this codebase
 * relies on). No card/gateway top-up path is built here — a customer who
 * wants to tip beyond their wallet balance tops up their wallet first via
 * the EXISTING wallet top-up flow (WalletTopUpService), not a new one.
 */
class TipService
{
    public function __construct(private WalletService $walletService)
    {
    }

    public function addTip(Booking $booking, User $customer, float $amount): BookingCompensation
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Tip amount must be positive.');
        }

        if ($booking->status !== 'completed') {
            throw new \RuntimeException('You can only tip after the booking is completed.');
        }

        if ($booking->customer_id !== $customer->id) {
            throw new \RuntimeException('This booking does not belong to you.');
        }

        if (! $booking->provider || ! $booking->provider->user) {
            throw new \RuntimeException('This booking has no assigned provider to tip.');
        }

        if (BookingCompensation::where('booking_id', $booking->id)->where('type', 'tip')->exists()) {
            throw new \RuntimeException('A tip has already been added for this booking.');
        }

        return DB::transaction(function () use ($booking, $customer, $amount) {
            $ref = 'tip:'.$booking->id.':'.Str::uuid();

            $this->walletService->debit($customer, $amount, "Tip for booking {$booking->code}", $ref.':debit');
            $this->walletService->credit($booking->provider->user, $amount, "Tip received for booking {$booking->code}", $ref.':credit');

            return BookingCompensation::create([
                'booking_id' => $booking->id,
                'provider_id' => $booking->provider_id,
                'type' => 'tip',
                'amount' => $amount,
                'status' => 'applied',
                'applied_by' => $customer->id,
                'wallet_transaction_ref' => $ref.':credit',
            ]);
        });
    }
}
