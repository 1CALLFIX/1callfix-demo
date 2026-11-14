<?php

namespace App\Console\Commands;

use App\Services\Homes\HouseListingService;
use Illuminate\Console\Command;

/** Property for rent — daily: visit requests the owner did not answer by the visit day close as "No reply in time". */
class HomesTidy extends Command
{
    protected $signature = 'homes:tidy';

    protected $description = 'Closes home visit requests not answered by the visit day';

    public function handle(HouseListingService $homes): int
    {
        $this->info('Closed '.$homes->tidy()['expired_requests'].' unanswered visit request(s).');

        return self::SUCCESS;
    }
}
