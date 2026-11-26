<?php

namespace App\Livewire\Organiser;

use App\Livewire\Partner\CertificateScreen;

/** Owner 2026-11-26: the "Verified 1CallFix Partner" certificate in the Events app. */
class Certificate extends CertificateScreen
{
    protected function app(): string
    {
        return 'organiser';
    }

    protected function layout(): string
    {
        return 'components.layouts.organiser';
    }
}
