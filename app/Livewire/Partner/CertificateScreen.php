<?php

namespace App\Livewire\Partner;

use App\Services\Partners\CertificateService;
use Livewire\Component;

/**
 * Owner 2026-11-26: "My certificate" in the Shop, Hotel, Rental and Events apps. For each of the partner's businesses
 * (a hall owner may have more than one hall): what is still needed (1CallFix approval) or the certificate itself (the
 * same design as the PDF), one big "Download to print" button, and "Get this year's certificate" once it expires.
 * App\Livewire\Seller\Certificate, Hotel\Certificate, Venue\Certificate and Organiser\Certificate pick the app.
 */
abstract class CertificateScreen extends Component
{
    public string $notice = '';

    /** seller | hotel | venue | organiser (CertificateService::APPS) */
    abstract protected function app(): string;

    abstract protected function layout(): string;

    /** Expired: a new QR and a new date for one of the partner's own businesses. */
    public function renew(int $business, CertificateService $certificates): void
    {
        $user = auth()->user();
        $own = $certificates->own($user, $this->app(), $business);
        abort_unless($own && $certificates->state($own) === 'ready', 403);

        $certificate = $certificates->certificateFor($user, $own);
        abort_unless($certificate->isExpired(), 403);
        $certificates->renew($certificate);
        $this->notice = 'Your new certificate is ready. Print it and put it up in place of the old one.';
    }

    public function render()
    {
        $certificates = app(CertificateService::class);
        $user = auth()->user();

        $items = $certificates->businesses($user, $this->app())->map(function ($business) use ($certificates, $user) {
            $state = $certificates->state($business);
            $certificate = $state === 'ready' ? $certificates->certificateFor($user, $business) : null;

            return [
                'id' => $business->id,
                'name' => $business->name,
                'label' => CertificateService::KINDS[$certificates->kind($business)][0],
                'state' => $state,
                'status' => $certificate ? $certificates->status($certificate, $business) : null,
                'c' => $certificate ? $certificates->data($certificate, $business) : null,
                'printUrl' => route($this->app().'.certificate.print', $business->id),
            ];
        });

        return view('livewire.partner.certificate', ['items' => $items])
            ->layout($this->layout(), ['title' => 'My certificate']);
    }
}
