<?php

namespace App\Console\Commands;

use App\Services\Qa\QaManifest;
use App\Services\Qa\QaSeeder;
use Illuminate\Console\Command;

/**
 * Builds a realistic, disposable QA dataset through real Actions/Services
 * — never raw inserts into a financial table. See QaSeeder's docblock.
 */
class QaSeedCommand extends Command
{
    protected $signature = 'qa:seed {--scale=default : small|default} {--yes : skip the confirmation prompt}';

    protected $description = 'Seed a realistic, disposable QA dataset (refuses to run against production)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('qa:seed refuses to run when APP_ENV=production. This command is for local/testing/qa environments only.');

            return self::FAILURE;
        }

        if (! app()->environment(['local', 'testing', 'qa'])) {
            $this->error('qa:seed requires APP_ENV to be one of: local, testing, qa. Current: '.app()->environment());

            return self::FAILURE;
        }

        if (QaManifest::exists()) {
            $this->error('A QA seed manifest already exists — run `qa:clean` first, or the new run\'s cleanup tracking would be incomplete.');

            return self::FAILURE;
        }

        if (! $this->option('yes') && ! $this->confirm('This will create a substantial dataset ('.$this->option('scale').' scale) on this environment ('.app()->environment().'). Continue?')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $scale = $this->option('scale');
        $this->info("Seeding QA dataset (scale={$scale})...");

        $start = microtime(true);
        $result = (new QaSeeder)->run($scale);
        $elapsed = round(microtime(true) - $start, 1);

        $this->newLine();
        $this->info("Done in {$elapsed}s. {$result['total_records']} records created across ".count($result['manifest_counts']).' tables.');
        $this->table(['Table', 'Count'], collect($result['manifest_counts'])->map(fn ($c, $t) => [$t, $c])->values()->all());

        $this->newLine();
        $this->info('Booking status distribution:');
        $this->table(['Status', 'Count'], collect($result['booking_status_distribution'])->map(fn ($c, $s) => [$s, $c])->values()->all());

        $this->newLine();
        $this->info('Subscription outcome distribution:');
        $this->table(['Outcome', 'Count'], collect($result['subscription_status_distribution'])->map(fn ($c, $s) => [$s, $c])->values()->all());

        return self::SUCCESS;
    }
}
