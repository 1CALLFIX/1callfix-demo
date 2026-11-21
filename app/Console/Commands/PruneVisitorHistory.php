<?php

namespace App\Console\Commands;

use App\Models\PageView;
use App\Models\VisitorPresence;
use Illuminate\Console\Command;

/** Live Visitors (2026-11-21): deletes page history older than config('visitors.keep_days') and presence older than 2 days. */
class PruneVisitorHistory extends Command
{
    protected $signature = 'visitors:prune';

    protected $description = 'Deletes old Live Visitors page history';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(7, (int) config('visitors.keep_days', 90)));
        $views = 0;
        do {
            $ids = PageView::where('created_at', '<', $cutoff)->orderBy('id')->limit(5000)->pluck('id');
            $views += $ids->isEmpty() ? 0 : PageView::whereIn('id', $ids)->delete();
        } while ($ids->count() === 5000);

        $presence = VisitorPresence::where('last_seen_at', '<', now()->subDays(2))->delete();

        $this->info("Deleted {$views} old page views and {$presence} old presence rows.");

        return self::SUCCESS;
    }
}
