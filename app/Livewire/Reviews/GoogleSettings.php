<?php

namespace App\Livewire\Reviews;

use App\Services\Reviews\GoogleReviews as G;
use App\Services\SettingsAuditor;
use App\Support\SuperAdminGate;
use Livewire\Component;

/**
 * Admin → System → Google reviews. Switch the home page's Google Business reviews on, set the Place ID, how many to
 * show, the minimum stars and how often to refresh, and pull the latest now. Super Admin only (checked in mount() and
 * every action), every change audit-logged. The API key itself is a server secret in .env and is never shown here.
 */
class GoogleSettings extends Component
{
    public bool $enabled = false;
    public string $placeId = '';
    public string $reviewUrl = '';
    public string $max = '5';
    public string $minRating = '4';
    public string $refreshHours = '24';

    public string $flashMessage = '';

    public function mount(): void
    {
        $this->authorizeAdmin();

        $this->enabled = G::enabled();
        $this->placeId = (string) \App\Models\Setting::get(G::PLACE_ID, '');
        $this->reviewUrl = (string) \App\Models\Setting::get(G::REVIEW_URL, '');
        $this->max = (string) G::max();
        $this->minRating = (string) G::minRating();
        $this->refreshHours = (string) G::refreshHours();
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $this->validate([
            'placeId' => ['nullable', 'string', function ($a, $v, $fail) {
                if (trim((string) $v) !== '' && ! G::isValidPlaceId(trim($v))) {
                    $fail('That does not look like a Google Place ID (letters, numbers, - and _ only, e.g. ChIJ…).');
                }
            }],
            'reviewUrl' => ['nullable', 'string', 'max:300', 'url:https', function ($a, $v, $fail) {
                $host = strtolower((string) parse_url((string) $v, PHP_URL_HOST));
                if (trim((string) $v) !== '' && preg_match('/(^|\.)(google\.com|goo\.gl|g\.page)$/', $host) !== 1) {
                    $fail('Use a Google link (google.com, g.page or goo.gl).');
                }
            }],
            'max' => ['required', 'integer', 'between:1,5'],
            'minRating' => ['required', 'integer', 'between:1,5'],
            'refreshHours' => ['required', 'integer', 'between:1,168'],
        ], [], ['placeId' => 'Place ID', 'reviewUrl' => 'write-a-review link', 'minRating' => 'minimum stars', 'refreshHours' => 'refresh hours']);

        $admin = auth()->user();
        $changed = false;
        foreach ([
            G::ENABLED => $this->enabled ? '1' : '0',
            G::PLACE_ID => trim($this->placeId),
            G::REVIEW_URL => trim($this->reviewUrl),
            G::MAX => (string) (int) $this->max,
            G::MIN_RATING => (string) (int) $this->minRating,
            G::REFRESH_HOURS => (string) (int) $this->refreshHours,
        ] as $key => $value) {
            $changed = SettingsAuditor::put($admin, $key, $value) || $changed;
        }

        $this->flashMessage = $changed ? 'Google reviews settings saved.' : 'Nothing changed.';
    }

    /** Fetch from Google right now using the SAVED settings. */
    public function refreshNow(): void
    {
        $this->authorizeAdmin();

        $this->flashMessage = app(G::class)->refresh(true);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(SuperAdminGate::allows(auth()->user()), 403, 'Only a Super Admin can change Google reviews settings.');
    }

    public function render()
    {
        return view('livewire.reviews.google-settings', [
            'keyConfigured' => G::apiKey() !== '',
            'fetchedAt' => G::fetchedAt(),
            'lastError' => (string) \App\Models\Setting::get(G::LAST_ERROR, ''),
            'showing' => app(G::class)->display(),
        ])->layout('layouts.admin', ['title' => 'Google reviews']);
    }
}
