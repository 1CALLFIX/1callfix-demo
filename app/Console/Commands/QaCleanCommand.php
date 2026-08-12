<?php

namespace App\Console\Commands;

use App\Services\Qa\QaCleaner;
use App\Services\Qa\QaManifest;
use Illuminate\Console\Command;

class QaCleanCommand extends Command
{
    protected $signature = 'qa:clean {--yes : skip the confirmation prompt}';

    protected $description = 'Delete everything the last qa:seed run created (refuses to run against production)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('qa:clean refuses to run when APP_ENV=production.');

            return self::FAILURE;
        }

        if (! QaManifest::exists()) {
            $this->info('No QA seed manifest found — nothing to clean.');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm('This will permanently delete the last qa:seed run\'s data on this environment ('.app()->environment().'). Continue?')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $this->info('Cleaning QA dataset...');
        $deleted = (new QaCleaner)->run();

        $this->table(['Table', 'Deleted'], collect($deleted)->map(fn ($c, $t) => [$t, $c])->values()->all());
        $this->info('Done. Manifest removed. Total rows deleted: '.array_sum($deleted));

        return self::SUCCESS;
    }
}
