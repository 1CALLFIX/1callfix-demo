<?php

namespace App\Livewire\Venue;

use App\Livewire\Partner\CertificateScreen;

/** Owner 2026-11-26: the "Verified 1CallFix Partner" certificate in the Rental app (one per function hall). */
class Certificate extends CertificateScreen
{
    protected function app(): string
    {
        return 'venue';
    }

    protected function layout(): string
    {
        return 'components.layouts.venue';
    }
}
