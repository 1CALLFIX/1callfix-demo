<?php

namespace App\Console\Commands;

use App\Actions\AcceptBookingAction;
use App\Actions\CompleteBookingAction;
use App\Models\Booking;
use App\Models\Provider;
use Illuminate\Console\Command;

class TestM4Flow extends Command
{
    protected $signature = 'test:m4-flow {booking_id} {provider_id=1}';
    protected $description = 'Runs accept + complete against an existing dispatched booking, printing each step.';

    public function handle(): int
    {
        $bookingId = (int) $this->argument('booking_id');
        $providerId = (int) $this->argument('provider_id');

        $booking = Booking::find($bookingId);
        if (!$booking) {
            $this->error("No booking found with id [{$bookingId}]");
            return self::FAILURE;
        }

        $this->info("--- Booking before accept ---");
        $this->line("Status: {$booking->status}");
        $attempts = $booking->dispatchAttempts()->get();
        $this->line("Dispatch attempts: {$attempts->count()}");
        foreach ($attempts as $a) {
            $this->line("  provider_id={$a->provider_id} status={$a->status} distance_km={$a->distance_km}");
        }

        $provider = Provider::find($providerId);
        if (!$provider) {
            $this->error("No provider found with id [{$providerId}]");
            return self::FAILURE;
        }

        $this->info("\n--- Accepting ---");
        try {
            $accepted = (new AcceptBookingAction())->execute($bookingId, $provider);
        } catch (\RuntimeException $e) {
            $this->error("Accept failed: {$e->getMessage()}");
            return self::FAILURE;
        }
        $this->line("Status: {$accepted->status}");
        $this->line("start_otp: {$accepted->start_otp}");
        $this->line("completion_otp: {$accepted->completion_otp}");

        $this->info("\n--- Completing ---");
        try {
            $completed = app(CompleteBookingAction::class)->execute($bookingId, $provider, $accepted->completion_otp);
        } catch (\RuntimeException $e) {
            $this->error("Complete failed: {$e->getMessage()}");
            return self::FAILURE;
        }
        $this->line("Status: {$completed->status}");
        $this->line("price_final: {$completed->price_final}");

        $this->info("\n--- Commission ---");
        $commission = $completed->commission;
        if ($commission) {
            $this->line("provider_commission: {$commission->provider_commission}");
            $this->line("franchise_commission: {$commission->franchise_commission}");
            $this->line("platform_commission: {$commission->platform_commission}");
        } else {
            $this->error("No commission row found!");
        }

        $this->info("\n--- Provider wallet ---");
        $wallet = $provider->user->wallet;
        $this->line($wallet ? "Balance: {$wallet->balance}" : "No wallet found!");

        return self::SUCCESS;
    }
}