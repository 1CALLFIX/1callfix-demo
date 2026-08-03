<?php

namespace App\Console\Commands;

use App\Actions\ResumeBookingAction;
use Illuminate\Console\Command;

class TestResumeBooking extends Command
{
    protected $signature = 'test:resume-booking {booking_id}';
    protected $description = 'Resumes a booking that was placed on hold, freeing its provider for new dispatch.';

    public function handle(): int
    {
        $bookingId = (int) $this->argument('booking_id');

        try {
            $booking = (new ResumeBookingAction())->execute($bookingId, 'Resumed for testing');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Booking [{$bookingId}] resumed. Status: {$booking->status}");
        return self::SUCCESS;
    }
}
