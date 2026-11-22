<?php

namespace App\Livewire\Marketing;

use App\Models\SocialPost;
use App\Services\Marketing\BufferApi;
use App\Services\Marketing\PostingRoutes as Routes;
use App\Services\SettingsAuditor;
use App\Support\SuperAdminGate;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "How each place posts" card on Admin → Marketing → Social posts (owner 2026-11-22: "we will on/off direct or buffer
 * from admin"). Super Admin only. One row per place with tap chips: Direct / Buffer / By hand, and a coloured badge
 * saying whether it is ready. Changes are audited (SettingsAuditor).
 */
class PostingRoutes extends Component
{
    public string $message = '';

    public function mount(): void
    {
        $this->authorizeIt();
    }

    private function authorizeIt(): void
    {
        abort_unless(SuperAdminGate::allows(auth()->user()), 403);
    }

    /** Buffer / Facebook connected or disconnected in the cards below: show the new state. */
    #[On('posting-routes-changed')]
    public function refreshRoutes(): void {}

    public function setRoute(string $platform, string $route): void
    {
        $this->authorizeIt();
        abort_unless(isset(SocialPost::PLATFORMS[$platform]) && in_array($route, Routes::options($platform), true), 422);
        SettingsAuditor::put(auth()->user(), Routes::PREFIX.$platform, $route);
        $label = SocialPost::PLATFORMS[$platform][0];
        $this->message = match ($route) {
            'direct' => "{$label} now posts by itself through our Facebook connection.",
            'buffer' => "{$label} now posts by itself through Buffer.",
            default => "{$label} is now By hand: posts wait for the Share button.",
        };
        $this->dispatch('posting-routes-changed');
    }

    public function render(Routes $routes)
    {
        $rows = [];
        foreach (SocialPost::PLATFORMS as $platform => [$label, $colour]) {
            // Buffer-only places show once Buffer is connected, so the card stays short until then.
            if (! in_array($platform, SocialPost::CORE_PLATFORMS, true) && ! app(BufferApi::class)->connected()) {
                continue;
            }
            $rows[] = [
                'platform' => $platform, 'label' => $label, 'colour' => $colour,
                'options' => Routes::options($platform), 'route' => $routes->route($platform), 'missing' => $routes->missing($platform),
            ];
        }

        return view('livewire.marketing.posting-routes', ['rows' => $rows, 'labels' => Routes::LABELS]);
    }
}
