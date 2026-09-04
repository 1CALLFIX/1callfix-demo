<?php

namespace App\Livewire\Provider;

use App\Actions\SetProviderOnlineStatusAction;
use App\Livewire\Provider\Concerns\InteractsWithProvider;
use Livewire\Component;

/**
 * Provider Mobile Nav session — a compact, always-on-screen twin of the
 * online/offline card on Dashboard.php. That card only renders on the
 * Dashboard page body, so a provider on Jobs/Earnings/History/Activity had
 * no way to go online/offline without navigating back to `/provider` first.
 * This component is mounted once in the shared provider layout header
 * (components/layouts/provider.blade.php) so the toggle is reachable from
 * every page, on every breakpoint — the drawer also renders it again for
 * the mobile case, per the brief ("reachable from the drawer OR a
 * persistent header element").
 *
 * Deliberately a SEPARATE component from Dashboard rather than an
 * extraction Dashboard.php delegates to: Dashboard.php and its existing
 * test suite (ProviderDashboardTest, ProviderJourneyPW1Test, etc.) assert
 * directly against Dashboard::class's own goOnline()/goOffline()/notice/
 * error properties and are left untouched by this change. Both components
 * call the exact same SetProviderOnlineStatusAction — no new write path.
 */
class OnlineToggle extends Component
{
    use InteractsWithProvider;

    public string $error = '';

    public function goOnline(?float $lat = null, ?float $lng = null): void
    {
        $this->error = '';

        app(SetProviderOnlineStatusAction::class)->execute($this->provider(), true, $lat, $lng);
    }

    public function goOffline(): void
    {
        $this->error = '';

        app(SetProviderOnlineStatusAction::class)->execute($this->provider(), false);
    }

    public function render()
    {
        return view('livewire.provider.online-toggle', [
            'provider' => $this->provider(),
        ]);
    }
}
