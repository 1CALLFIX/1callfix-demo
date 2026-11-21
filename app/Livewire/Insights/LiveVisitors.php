<?php

namespace App\Livewire\Insights;

use App\Services\SettingsAuditor;
use App\Services\TimezoneResolver;
use App\Services\Visitors\PageClassifier;
use App\Services\Visitors\VisitorInsights;
use App\Services\Visitors\VisitTracker;
use App\Support\ModuleSales;
use Livewire\Component;

/**
 * Admin → Overview → Live Visitors (owner 2026-11-21): "how many users are online, what they are checking and what they
 * are going to book". Refreshes itself every 20 seconds. Behind `visitors.view`, row-scoped (VisitorInsights); names and
 * phone numbers of signed-in visitors need `customers.view` too. Super Admin can switch the tracking off (audited).
 */
class LiveVisitors extends Component
{
    public const PERIODS = ['today' => 'Today', 'week' => 'Last 7 days', 'month' => 'Last 30 days'];

    public string $period = 'today';

    public string $flashMessage = '';

    protected $queryString = ['period' => ['except' => 'today']];

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasPermissionAnywhere('visitors.view'), 403, 'You do not have permission to view Live Visitors.');
    }

    public function setPeriod(string $period): void
    {
        $this->period = array_key_exists($period, self::PERIODS) ? $period : 'today';
    }

    public function toggleTracking(): void
    {
        abort_unless(auth()->user()?->role === 'super_admin', 403, 'Only Super Admin can switch visitor tracking on or off.');

        $on = ! VisitTracker::enabled();
        SettingsAuditor::put(auth()->user(), VisitTracker::SETTING, $on ? '1' : '0');
        $this->flashMessage = $on ? 'Visitor tracking is on.' : 'Visitor tracking is off. Nothing new is recorded until you switch it on.';
    }

    /** Popularity line on item pages: tap a chip. 0 = off (setting cleared). Super Admin only, audited. */
    public const POPULARITY_CHOICES = ['views' => [0, 3, 5, 10, 20], 'orders' => [0, 5, 10, 25, 50]];

    public function setPopularity(string $which, int $value): void
    {
        abort_unless(auth()->user()?->role === 'super_admin', 403, 'Only Super Admin can change what customers see.');
        abort_unless(in_array($value, self::POPULARITY_CHOICES[$which] ?? [], true), 422);

        $key = $which === 'views' ? \App\Services\Visitors\Popularity::VIEWS_MIN : \App\Services\Visitors\Popularity::ORDERS_MIN;
        SettingsAuditor::put(auth()->user(), $key, $value > 0 ? (string) $value : null);
        $this->flashMessage = $value > 0
            ? ($which === 'views' ? "Item pages now show how many people viewed them, once it is {$value} or more." : "Item pages now show their orders in the past month, once it is {$value} or more.")
            : ($which === 'views' ? 'The "people viewed" line is off.' : 'The "orders in the past month" line is off.');
    }

    private function from()
    {
        return match ($this->period) {
            'week' => now()->subDays(7),
            'month' => now()->subDays(30),
            default => now(app(TimezoneResolver::class)->platformTimezone())->startOfDay()->utc(),
        };
    }

    public function render()
    {
        $user = auth()->user();
        abort_unless($user?->hasPermissionAnywhere('visitors.view'), 403);

        $insights = new VisitorInsights($user);
        $from = $this->from();
        $moduleSales = ModuleSales::summary($user, $from);
        // Every module; a row shows only when it had visits or orders in the period.
        $modules = array_keys(PageClassifier::MODULE_LABELS);

        return view('livewire.insights.live-visitors', [
            'online' => $insights->onlineNow(),
            'totals' => $insights->totals($from, $moduleSales),
            'topItems' => $insights->topItems($from),
            'sources' => $insights->sources($from),
            'funnel' => $insights->funnel($from, $moduleSales, $modules),
            'aboutToBook' => $insights->aboutToBook(),
            'periods' => self::PERIODS,
            'trackingOn' => VisitTracker::enabled(),
            'popularity' => ['views' => (int) \App\Services\Visitors\Popularity::minimum(\App\Services\Visitors\Popularity::VIEWS_MIN),
                'orders' => (int) \App\Services\Visitors\Popularity::minimum(\App\Services\Visitors\Popularity::ORDERS_MIN)],
            'popularityChoices' => self::POPULARITY_CHOICES,
            'isSuperAdmin' => $user->role === 'super_admin',
            'canSeePeople' => $user->hasPermissionAnywhere('customers.view'),
            'onlineMinutes' => (int) config('visitors.online_minutes', 3),
            'aboutMinutes' => (int) config('visitors.about_to_book_minutes', 30),
        ])->layout('layouts.admin', ['title' => 'Live Visitors']);
    }
}
