<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Services\Partners\CertificateService;
use Barryvdh\DomPDF\Facade\Pdf;

/** Owner 2026-11-26: the "Verified 1CallFix Partner" certificate and stickers as a PDF (the partner's own business only). */
class CertificateController extends Controller
{
    public function __construct(private CertificateService $certificates)
    {
    }

    /** /seller|hotel|venue|organiser/certificate/{business}/print */
    public function pdf(string $business, string $app) // route parameter first, then the route default
    {
        abort_unless(isset(CertificateService::APPS[$app]), 404);
        $user = auth()->user();
        $own = $this->certificates->own($user, $app, (int) $business);
        abort_unless($own && $this->certificates->state($own) === 'ready', 404);

        $certificate = $this->certificates->certificateFor($user, $own);
        abort_if($this->certificates->status($certificate, $own) !== 'active', 404);

        $c = $this->certificates->data($certificate, $own);

        return Pdf::loadView('partner.certificate.pdf', ['c' => $c])
            ->setPaper('a4', 'landscape')
            ->stream('1CallFix-certificate-'.$c['code'].'.pdf');
    }
}
