<?php

namespace App\Livewire\Provider;

use App\Livewire\Provider\Concerns\InteractsWithProvider;
use App\Models\ActivityLog;
use App\Models\BookingStatusHistory;
use App\Models\DispatchAttempt;
use App\Models\Provider;
use App\Models\WalletTransaction;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * PHASE PW1 §10 — one reverse-chronological feed merged from the four
 * tables that already record what a partner did: booking status
 * transitions, wallet movements, dispatch offers, and the web
 * online/offline audit rows. No new table, all read-only, every query hard
 * scoped to this partner.
 */
class Activity extends Component
{
    use InteractsWithProvider;

    public int $show = 60;

    public function showMore(): void
    {
        $this->show = min($this->show + 60, 300);
    }

    public function render()
    {
        $provider = $this->provider();
        $cap = 300;

        $statusRows = BookingStatusHistory::query()
            ->whereHas('booking', fn ($q) => $q->where('provider_id', $provider->id))
            ->with('booking:id,code')
            ->latest('changed_at')
            ->limit($cap)
            ->get()
            ->map(fn ($r) => [
                'at' => Carbon::parse($r->changed_at),
                'kind' => 'job',
                'text' => trim(($r->booking?->code ? $r->booking->code.' — ' : '').str_replace('_', ' ', $r->status)
                    .($r->note ? ' ('.$r->note.')' : '')),
            ]);

        $walletRows = WalletTransaction::query()
            ->whereHas('wallet', fn ($q) => $q->where('user_id', $provider->user_id))
            ->latest('id')
            ->limit($cap)
            ->get()
            ->map(fn ($t) => [
                'at' => Carbon::parse($t->created_at),
                'kind' => $t->is_credit ? 'credit' : 'debit',
                'text' => ($t->is_credit ? '+' : '−').number_format((float) $t->amount, 2).' — '.$t->reason,
            ]);

        $offerRows = DispatchAttempt::query()
            ->where('provider_id', $provider->id)
            ->with('booking:id,code')
            ->latest('id')
            ->limit($cap)
            ->get()
            ->map(fn ($a) => [
                'at' => Carbon::parse($a->responded_at ?? $a->notified_at ?? $a->created_at),
                'kind' => 'offer',
                'text' => ($a->booking?->code ? $a->booking->code.' — ' : 'Offer — ').'offer '.$a->status,
            ]);

        $auditRows = ActivityLog::query()
            ->where('subject_type', Provider::class)
            ->where('subject_id', $provider->id)
            ->latest('id')
            ->limit($cap)
            ->get()
            ->map(fn ($l) => [
                'at' => Carbon::parse($l->created_at),
                'kind' => 'status',
                'text' => $l->description,
            ]);

        $feed = $statusRows
            ->concat($walletRows)
            ->concat($offerRows)
            ->concat($auditRows)
            ->sortByDesc(fn ($row) => $row['at']->getTimestamp())
            ->values();

        return view('livewire.provider.activity', [
            'feed' => $feed->take($this->show),
            'hasMore' => $feed->count() > $this->show,
        ])->layout('components.layouts.provider', ['title' => 'Activity']);
    }
}
