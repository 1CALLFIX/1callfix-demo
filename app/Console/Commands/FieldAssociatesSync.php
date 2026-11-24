<?php

namespace App\Console\Commands;

use App\Services\FieldAssociates\FieldAssociateTracker;
use Illuminate\Console\Command;

/**
 * Field associates release A (2026-11-24): once a day, write the rewards that became due (a first order whose return
 * window has passed, products approved without a page being opened), cancel refunded first orders and update the
 * month's bonus, so the month's sheet is right even if nobody opened the pages.
 */
class FieldAssociatesSync extends Command
{
    protected $signature = 'field-associates:sync';

    protected $description = 'Field associates: write rewards that became due, cancel refunded first orders, update monthly bonuses';

    public function handle(FieldAssociateTracker $tracker): int
    {
        $this->line('Shops checked: '.$tracker->syncAll());

        return self::SUCCESS;
    }
}
