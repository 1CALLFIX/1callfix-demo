<?php

namespace App\Console\Commands;

use App\Services\Marketplace\MarketplaceReturnService;
use Illuminate\Console\Command;

/** MANUAL MONEY ACTIONS — escalation step for shop return refunds. A no-op while refund.shop_return.escalate_after_hours is unset. */
class EscalateShopReturnRefunds extends Command
{
    protected $signature = 'refunds:escalate-shop-returns';

    protected $description = 'Raises the escalation level of shop return refunds waiting past refund.shop_return.escalate_after_hours';

    public function handle(MarketplaceReturnService $service): int
    {
        $this->info("Shop return refunds: {$service->escalateOverdue()} escalation(s) recorded.");

        return self::SUCCESS;
    }
}
