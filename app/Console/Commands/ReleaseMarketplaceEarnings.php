<?php

namespace App\Console\Commands;

use App\Services\Marketplace\MarketplaceSettlementService;
use Illuminate\Console\Command;

/**
 * E-commerce Phase 0 — pays sellers their held share once the return window (marketplace.return_window_days,
 * default 3) has passed, and recovers riders' cash first where the order was cash on delivery.
 */
class ReleaseMarketplaceEarnings extends Command
{
    protected $signature = 'marketplace:release-earnings';

    protected $description = 'Pays sellers their held shop earnings after the return window';

    public function handle(MarketplaceSettlementService $settlements): int
    {
        $released = $settlements->releaseDue();

        $this->info("Shop earnings released: {$released}.");

        return self::SUCCESS;
    }
}
