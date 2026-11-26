<?php

namespace App\Livewire\Seller;

use App\Livewire\Partner\CertificateScreen;

/** Owner 2026-11-26: the "Verified 1CallFix Partner" certificate in the Shop app (shops and restaurants). */
class Certificate extends CertificateScreen
{
    protected function app(): string
    {
        return 'seller';
    }

    protected function layout(): string
    {
        return 'components.layouts.seller';
    }
}
