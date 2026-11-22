<?php

namespace App\Livewire\Marketing;

use App\Http\Controllers\Admin\MetaConnectController;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\Marketing\MetaGraph;
use App\Support\SuperAdminGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Livewire\Component;

/**
 * "Connect Facebook & Instagram" card on Admin → Marketing → Social posts (owner 2026-11-22). Super Admin only. Three
 * numbered steps: 1 the Meta app's ID + secret (the secret is write-only: stored encrypted, never shown again, never
 * logged), 2 Connect (approve once on Facebook). Whether Facebook / Instagram use this connection, Buffer or the Share
 * button is chosen in "How each place posts" (PostingRoutes, 2026-11-22).
 */
class MetaConnect extends Component
{
    public string $appId = '';
    public string $appSecret = '';
    public string $message = '';

    public function mount(MetaGraph $meta): void
    {
        $this->authorizeIt();
        $this->appId = (string) $meta->appId();
    }

    private function authorizeIt(): void
    {
        abort_unless(SuperAdminGate::allows(auth()->user()), 403);
    }

    public function saveApp(): void
    {
        $this->authorizeIt();
        $this->appId = trim($this->appId);
        $this->appSecret = trim($this->appSecret);
        $hasSecret = (string) Setting::get(MetaGraph::APP_SECRET, '') !== '';
        $this->validate([
            'appId' => ['required', 'regex:/^\d{6,20}$/'],
            'appSecret' => [$hasSecret ? 'nullable' : 'required', 'regex:/^[a-f0-9]{32}$/i'],
        ], [
            'appId.regex' => 'The App ID is a number, e.g. 445952729385294.',
            'appSecret.regex' => 'The App secret is 32 letters and numbers (Meta app → App settings → Basic → Show).',
            'appSecret.required' => 'Paste the App secret.',
        ]);

        Setting::set(MetaGraph::APP_ID, $this->appId);
        if ($this->appSecret !== '') {
            Setting::set(MetaGraph::APP_SECRET, Crypt::encryptString($this->appSecret));
        }
        ActivityLogger::log(auth()->user(), 'setting', 0, 'Meta app for automatic posting saved', [
            'app_id' => $this->appId, 'secret_changed' => $this->appSecret !== '',
        ]);
        $this->appSecret = '';
        $this->message = 'Saved. Now press Connect.';
    }

    public function choosePage(int $index, MetaGraph $meta): void
    {
        $this->authorizeIt();
        $stored = Cache::get(MetaConnectController::CHOICES.auth()->id());
        $pages = $stored ? Crypt::decrypt($stored) : [];
        if (! isset($pages[$index])) {
            $this->message = 'That choice expired. Press Connect again.';

            return;
        }
        $meta->savePage($pages[$index]);
        Cache::forget(MetaConnectController::CHOICES.auth()->id());
        ActivityLogger::log(auth()->user(), 'setting', 0, 'Facebook Page connected for automatic posting', [
            'page' => $pages[$index]['name'], 'instagram' => $pages[$index]['ig_username'],
        ]);
        $this->message = 'Connected: '.$pages[$index]['name'].'.';
        $this->dispatch('posting-routes-changed');
    }

    public function disconnect(MetaGraph $meta): void
    {
        $this->authorizeIt();
        $meta->disconnect();
        ActivityLogger::log(auth()->user(), 'setting', 0, 'Facebook Page disconnected from automatic posting');
        $this->message = 'Disconnected. Places set to Direct wait for the Share button until Facebook is connected again.';
        $this->dispatch('posting-routes-changed');
    }

    public function render(MetaGraph $meta)
    {
        $stored = Cache::get(MetaConnectController::CHOICES.auth()->id());

        return view('livewire.marketing.meta-connect', [
            'hasApp' => $meta->hasApp(),
            'hasSecret' => (string) Setting::get(MetaGraph::APP_SECRET, '') !== '',
            'connected' => $meta->connected(),
            'pageName' => Setting::get(MetaGraph::PAGE_NAME),
            'igUsername' => Setting::get(MetaGraph::IG_USERNAME),
            'broken' => Setting::get(MetaGraph::BROKEN),
            'choices' => $stored ? array_map(fn ($p) => ['name' => $p['name'], 'ig' => $p['ig_username']], Crypt::decrypt($stored)) : [],
            'redirectUri' => $meta->redirectUri(),
            'flash' => session('meta_message'),
        ]);
    }
}
