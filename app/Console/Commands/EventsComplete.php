<?php

namespace App\Console\Commands;

use App\Services\Events\EventTicketService;
use Illuminate\Console\Command;

/**
 * Events E3 — events over for longer than Event Settings' payout hold are finished, and each paid order pays the
 * organiser (commission kept, the rest to the organiser's wallet for the usual payout). Safe to run again.
 */
class EventsComplete extends Command
{
    protected $signature = 'events:complete';

    protected $description = 'Finishes events past their payout hold and credits the organisers their ticket earnings';

    public function handle(EventTicketService $tickets): int
    {
        $this->info('Finished '.$tickets->completeEvents().' event(s).');

        return self::SUCCESS;
    }
}
