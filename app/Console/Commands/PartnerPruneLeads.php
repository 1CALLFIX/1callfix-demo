<?php

namespace App\Console\Commands;

use App\Models\PartnerLead;
use App\Support\PartnerPage\PartnerPageSettings;
use Illuminate\Console\Command;

/**
 * REF 1CF-PARTNER-PAGE-001: deletes partner leads untouched for `partner_page.lead_retention_days` days.
 * With the setting unset (the default) nothing is ever deleted. Not scheduled: the owner decides when to run it.
 */
class PartnerPruneLeads extends Command
{
    protected $signature = 'partner:prune-leads {--dry-run : count only}';

    protected $description = 'Delete partner leads older than the configured retention (default: keep everything)';

    public function handle(): int
    {
        $days = PartnerPageSettings::retentionDays();
        if ($days === null) {
            $this->info('Lead retention is not set: keeping every lead.');

            return self::SUCCESS;
        }

        $query = PartnerLead::where('updated_at', '<', now()->subDays($days));
        $count = $query->count();

        if (! $this->option('dry-run')) {
            $query->delete();
        }
        $this->info(($this->option('dry-run') ? 'Would delete ' : 'Deleted ').$count." lead(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
