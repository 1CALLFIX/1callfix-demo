<?php

namespace App\Console\Commands;

use App\Actions\CompleteBookingAction;
use App\Models\Booking;
use App\Models\Provider;
use Illuminate\Console\Command;

class TestCompleteBooking extends Command
{
    protected $signature = 'test:complete-booking {booking_id} {provider_id=1}';
    protected $description = 'Completes an in-progress test booking, generating completion_otp first if missing.';

    public function handle(): int
    {
        $bookingId = (int) $this->argument('booking_id');
        $providerId = (int) $this->argument('provider_id');

        $booking = Booking::find($bookingId);
        if (!$booking) {
            $this->error("No booking found with id [{$bookingId}]");
            return self::FAILURE;
        }

        if (empty($booking->completion_otp)) {
            $booking->completion_otp = (string) random_int(1000, 9999);
            $booking->save();
            $this->line("Generated missing completion_otp: {$booking->completion_otp}");
        }

        $provider = Provider::find($providerId);

        try {
            $completed = app(CompleteBookingAction::class)->execute($bookingId, $provider, $booking->completion_otp);
        } catch (\RuntimeException $e) {
            $this->error("Complete failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->info("Booking [{$bookingId}] completed. Status: {$completed->status}, price_final: {$completed->price_final}");

        $commission = $completed->commission;
        if ($commission) {
            $this->line("Commission — provider: {$commission->provider_commission}, franchise: {$commission->franchise_commission}, platform: {$commission->platform_commission}");
        } else {
            $this->error("No commission row created!");
        }

        $wallet = $provider->user->wallet;
        $this->line($wallet ? "Provider wallet balance: {$wallet->balance}" : "No wallet found!");

        return self::SUCCESS;
    }
}
