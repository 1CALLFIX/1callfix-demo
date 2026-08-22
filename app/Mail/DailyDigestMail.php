<?php

namespace App\Mail;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sidebar Reorganization + Daily Digest session — the real Mailable behind
 * `digest:send-daily` (see App\Console\Commands\SendDailyDigest and
 * App\Services\Reporting\DailyDigestDispatchService). $payload is exactly
 * what App\Services\Reporting\DailyDigestService::forUser() returned for
 * THIS recipient — already scoped (Super Admin = platform-wide, Franchise
 * admin = their own franchise only) before it ever reaches this class; the
 * Mailable itself does no querying/scoping of its own.
 */
class DailyDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array{kpis: array<string, mixed>, insights: ?array<string, \Illuminate\Support\Collection>} $payload
     */
    public function __construct(
        public User $recipient,
        public array $payload,
        public string $forDate,
    ) {
    }

    public function build(): self
    {
        $platformName = Setting::get('branding.platform_name', '1CallFix Admin');
        $currencySymbol = Setting::get('locale.currency_symbol', '₹');

        return $this->subject("{$platformName} — Daily Digest for {$this->forDate}")
            ->view('emails.daily-digest')
            ->with([
                'recipient' => $this->recipient,
                'kpis' => $this->payload['kpis'],
                'insights' => $this->payload['insights'],
                'forDate' => $this->forDate,
                'platformName' => $platformName,
                'currencySymbol' => $currencySymbol,
            ]);
    }
}
