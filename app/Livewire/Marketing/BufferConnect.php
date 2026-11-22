<?php

namespace App\Livewire\Marketing;

use App\Models\Setting;
use App\Models\SocialPost;
use App\Services\ActivityLogger;
use App\Services\Marketing\BufferApi;
use App\Support\SuperAdminGate;
use Livewire\Component;

/**
 * "Connect Buffer" card on Admin → Marketing → Social posts (owner 2026-11-22). Super Admin only. Two numbered steps:
 * 1 paste the Buffer API key (checked with Buffer, stored encrypted, never shown again, never logged), 2 which Buffer
 * channel each place posts to (auto-matched; tap to change; Refresh after adding a channel in Buffer).
 */
class BufferConnect extends Component
{
    public string $apiKey = '';
    public string $message = '';
    public string $messageType = 'info';

    public function mount(): void
    {
        $this->authorizeIt();
    }

    private function authorizeIt(): void
    {
        abort_unless(SuperAdminGate::allows(auth()->user()), 403);
    }

    private function say(string $message, string $type = 'info'): void
    {
        $this->message = $message;
        $this->messageType = $type;
    }

    public function connect(BufferApi $buffer): void
    {
        $this->authorizeIt();
        $this->apiKey = trim($this->apiKey);
        $this->validate(['apiKey' => ['required', 'string', 'min:20', 'max:300', 'regex:/^\S+$/']], [
            'apiKey.required' => 'Paste the API key from Buffer → Settings → API.',
            'apiKey.min' => 'That looks too short for a Buffer API key. Copy the whole key.',
            'apiKey.regex' => 'The key has no spaces. Copy it again from Buffer.',
        ]);
        try {
            $buffer->connect($this->apiKey);
        } catch (\Throwable $e) {
            $this->say('Not connected: '.$e->getMessage(), 'error');

            return;
        } finally {
            $this->apiKey = '';
        }
        ActivityLogger::log(auth()->user(), 'setting', 0, 'Buffer connected for automatic posting', [
            'organisation' => Setting::get(BufferApi::ORG_NAME), 'channels' => count($buffer->channels()),
        ]);
        $this->say('Connected to Buffer. Now choose Buffer for the places you want above.', 'success');
        $this->dispatch('posting-routes-changed');
    }

    public function refreshChannels(BufferApi $buffer): void
    {
        $this->authorizeIt();
        try {
            $buffer->refresh();
        } catch (\Throwable $e) {
            $this->say('Could not read the channels: '.$e->getMessage(), 'error');

            return;
        }
        $this->say('Channels refreshed.', 'success');
        $this->dispatch('posting-routes-changed');
    }

    public function choose(string $platform, string $channelId, BufferApi $buffer): void
    {
        $this->authorizeIt();
        abort_unless(isset(BufferApi::SERVICES[$platform]), 422);
        try {
            $buffer->choose($platform, $channelId);
        } catch (\Throwable $e) {
            $this->say($e->getMessage(), 'error');

            return;
        }
        ActivityLogger::log(auth()->user(), 'setting', 0, 'Buffer channel chosen for '.SocialPost::PLATFORMS[$platform][0], ['channel' => $channelId]);
        $this->say(SocialPost::PLATFORMS[$platform][0].' channel saved.', 'success');
    }

    public function disconnect(BufferApi $buffer): void
    {
        $this->authorizeIt();
        $buffer->disconnect();
        ActivityLogger::log(auth()->user(), 'setting', 0, 'Buffer disconnected from automatic posting');
        $this->say('Disconnected. Places set to Buffer wait for the Share button until Buffer is connected again.');
        $this->dispatch('posting-routes-changed');
    }

    public function render(BufferApi $buffer)
    {
        $channels = $buffer->channels();
        $map = $buffer->map();
        $groups = [];
        foreach (BufferApi::SERVICES as $platform => $_) {
            $list = array_values(array_filter($channels, fn ($c) => $c['platform'] === $platform));
            if ($list !== []) {
                $groups[] = ['platform' => $platform, 'label' => SocialPost::PLATFORMS[$platform][0], 'colour' => SocialPost::PLATFORMS[$platform][1],
                    'channels' => $list, 'chosen' => $map[$platform] ?? null];
            }
        }

        return view('livewire.marketing.buffer-connect', [
            'hasKey' => $buffer->apiKey() !== null,
            'connected' => $buffer->connected(),
            'broken' => Setting::get(BufferApi::BROKEN),
            'orgName' => Setting::get(BufferApi::ORG_NAME),
            'groups' => $groups,
            'others' => array_values(array_filter($channels, fn ($c) => $c['platform'] === null)),
        ]);
    }
}
