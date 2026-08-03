<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Provider;
use Illuminate\Console\Command;

class TestDiagnose extends Command
{
    protected $signature = 'test:diagnose';
    protected $description = 'Dumps the current real state of test bookings 1 & 2 and provider 1 — no assumptions.';

    public function handle(): int
    {
        foreach ([1, 2] as $id) {
            $booking = Booking::find($id);
            $this->info("--- Booking #{$id} ---");
            if (!$booking) {
                $this->line("  (does not exist)");
                continue;
            }
            $this->line("  status: {$booking->status}");
            $this->line("  provider_id: " . ($booking->provider_id ?? 'null'));
            $this->line("  hold_category: " . ($booking->hold_category ?? 'null'));
            $this->line("  hold_reason: " . ($booking->hold_reason ?? 'null'));
            $attempts = $booking->dispatchAttempts()->get();
            $this->line("  dispatch_attempts: {$attempts->count()}");
            foreach ($attempts as $a) {
                $this->line("    provider_id={$a->provider_id} status={$a->status} notified_at={$a->notified_at}");
            }
        }

        $this->info("\n--- Provider #1 ---");
        $provider = Provider::find(1);
        if (!$provider) {
            $this->line("  (does not exist)");
            return self::SUCCESS;
        }
        $this->line("  zone_id: {$provider->zone_id}");
        $this->line("  is_online: " . ($provider->is_online ? 'true' : 'false'));
        $this->line("  is_active: " . ($provider->is_active ? 'true' : 'false'));
        $this->line("  kyc_status: {$provider->kyc_status}");
        $this->line("  skills: " . json_encode($provider->skills));
        $this->line("  current_lat/lng: {$provider->current_lat}, {$provider->current_lng}");

        $this->info("\n--- Queued jobs waiting ---");
        $jobs = \DB::table('jobs')->get(['id', 'queue', 'available_at', 'attempts']);
        $this->line("  count: {$jobs->count()}");
        foreach ($jobs as $j) {
            $available = date('Y-m-d H:i:s', $j->available_at);
            $this->line("    id={$j->id} attempts={$j->attempts} available_at={$available}");
        }

        return self::SUCCESS;
    }
}
