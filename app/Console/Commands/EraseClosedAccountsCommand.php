<?php

namespace App\Console\Commands;

use App\Services\Account\AccountDeletionService;
use Illuminate\Console\Command;

/** Daily: erase the personal details of accounts closed 30+ days ago (Google Play account deletion, 2026-11-05). */
class EraseClosedAccountsCommand extends Command
{
    protected $signature = 'accounts:erase-closed';

    protected $description = 'Erase personal details of accounts closed more than 30 days ago';

    public function handle(AccountDeletionService $deletion): int
    {
        $this->info('Erased: '.$deletion->eraseDue());

        return self::SUCCESS;
    }
}
