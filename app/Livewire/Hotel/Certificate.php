<?php

namespace App\Livewire\Hotel;

use App\Livewire\Partner\CertificateScreen;

/** Owner 2026-11-26: the "Verified 1CallFix Partner" certificate in the Hotel app. */
class Certificate extends CertificateScreen
{
    protected function app(): string
    {
        return 'hotel';
    }

    protected function layout(): string
    {
        return 'components.layouts.hotel';
    }
}
