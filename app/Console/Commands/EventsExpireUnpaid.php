<?php

namespace App\Console\Commands;

use App\Services\Events\EventTicketService;
use Illuminate\Console\Command;

/**
 * Events E2 — no payment, no ticket: online ticket orders not paid within Event Settings' unpaid hold are cancelled and
 * their tickets go back on sale. A payment captured later is refunded in full by EventTicketService::markPaid().
 */
class EventsExpireUnpaid extends Command
{
    protected $signature = 'events:expire-unpaid';

    protected $description = 'Cancels online ticket orders not paid within event.unpaid_hold_minutes and puts their tickets back on sale';

    public function handle(EventTicketService $tickets): int
    {
        $this->info('Expired '.$tickets->expireUnpaid().' unpaid ticket order(s).');

        return self::SUCCESS;
    }
}
