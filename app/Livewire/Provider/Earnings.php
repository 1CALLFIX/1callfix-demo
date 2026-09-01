<?php

namespace App\Livewire\Provider;

use App\Livewire\Provider\Concerns\InteractsWithProvider;
use App\Models\Commission;
use App\Services\WalletService;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * PHASE PW1 §7.1 — "what you've earned + what's in your wallet". Read-only.
 * Per-job earnings are the `provider_commission` column CommissionService
 * already wrote per completed booking; the balance is WalletService's. No
 * payout / withdrawal here — that is a separate, admin-owned surface.
 */
class Earnings extends Component
{
    use InteractsWithProvider;

    /** 'week' | 'month' | 'all' */
    public string $range = 'month';

    public function setRange(string $range): void
    {
        $this->range = in_array($range, ['week', 'month', 'all'], true) ? $range : 'month';
    }

    public function render(WalletService $wallet)
    {
        $provider = $this->provider();

        $since = match ($this->range) {
            'week' => Carbon::now()->subWeek(),
            'all' => null,
            default => Carbon::now()->subMonth(),
        };

        $rows = Commission::query()
            ->whereHas('booking', fn ($q) => $q->where('provider_id', $provider->id))
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->with(['booking:id,code,status,completed_at,price_final'])
            ->latest('id')
            ->limit(200)
            ->get();

        return view('livewire.provider.earnings', [
            'rows' => $rows,
            'rangeTotal' => (float) $rows->sum('provider_commission'),
            'jobsInRange' => $rows->count(),
            'walletBalance' => $wallet->balance($provider->user),
        ])->layout('components.layouts.provider', ['title' => 'Earnings']);
    }
}
