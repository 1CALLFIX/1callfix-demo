<?php

namespace App\Console\Commands;

use App\Services\Reviews\GoogleReviews;
use Illuminate\Console\Command;

/** Pulls the Google Business reviews shown on the home page. Runs hourly but only calls Google every `refresh_hours`. */
class RefreshGoogleReviews extends Command
{
    protected $signature = 'google-reviews:refresh {--force : Fetch now even if the saved copy is still fresh}';

    protected $description = 'Refresh the Google Business reviews shown on the home page';

    public function handle(GoogleReviews $reviews): int
    {
        $this->line($reviews->refresh((bool) $this->option('force')));

        return self::SUCCESS;
    }
}
