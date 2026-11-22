<?php

namespace App\Services\Marketing;

use App\Models\Setting;
use App\Models\SocialPost;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Posting through the company's Buffer account (owner 2026-11-22: "on buffer they will get autoposted … we will add
 * link with buffer account"). Buffer already holds Google's approval for Business Profile posts, so Google Business,
 * LinkedIn, X and Threads can post by themselves without our own approvals; Facebook / Instagram can go either way
 * (PostingRoutes decides per platform).
 *
 * Buffer's GraphQL API (https://api.buffer.com) with the account's personal API key (Buffer → Settings → API), stored
 * encrypted, never shown again, never logged. Connect reads the organisations and channels and auto-matches each of our
 * platforms to a channel; the Super Admin can change the match. Enum values are read from the API itself at Connect
 * (introspection), so a renamed value does not silently break posting; the defaults below are used only if that fails.
 */
class BufferApi
{
    public const ENDPOINT = 'https://api.buffer.com';

    public const API_KEY = 'marketing.buffer.api_key';      // Crypt::encryptString
    public const ORG_NAME = 'marketing.buffer.org_name';
    public const CHANNELS = 'marketing.buffer.channels';    // JSON list of {id, name, service, platform, usable}
    public const MAP = 'marketing.buffer.map';              // JSON {platform: channel id}
    public const ENUMS = 'marketing.buffer.enums';          // JSON {EnumName: [values]}
    public const BROKEN = 'marketing.buffer.broken';        // why the last call said the key no longer works

    /** Our platform => how Buffer names the service. */
    public const SERVICES = [
        'facebook' => ['facebook'],
        'instagram' => ['instagram'],
        'google_business' => ['googlebusiness', 'google_business', 'google', 'gbp', 'googlebusinessprofile'],
        'linkedin' => ['linkedin'],
        'x' => ['twitter', 'x'],
        'threads' => ['threads'],
    ];

    /** Longest text each place takes (characters); the caption is shortened, never the link. */
    public const TEXT_LIMITS = ['google_business' => 1500, 'x' => 280, 'threads' => 500, 'instagram' => 2150, 'linkedin' => 3000];

    private const ENUM_NAMES = ['ShareMode', 'SchedulingType', 'PostType', 'PostTypeFacebook', 'PostTypeGoogleBusiness', 'GoogleBusinessPostActionType'];

    public function apiKey(): ?string
    {
        $stored = (string) Setting::get(self::API_KEY, '');
        if ($stored === '') {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Connected = a key, at least one channel read from Buffer, and no call has since said the key stopped working. */
    public function connected(): bool
    {
        return $this->apiKey() !== null && $this->channels() !== [] && ! Setting::get(self::BROKEN);
    }

    /** @return list<array{id: string, name: string, service: string, platform: ?string, usable: bool}> */
    public function channels(): array
    {
        $list = json_decode((string) Setting::get(self::CHANNELS, ''), true);

        return is_array($list) ? array_values($list) : [];
    }

    /** @return array<string, string> platform => channel id */
    public function map(): array
    {
        $map = json_decode((string) Setting::get(self::MAP, ''), true);

        return is_array($map) ? array_filter($map, 'is_string') : [];
    }

    /** The Buffer channel a platform posts to, if one is set and still usable. */
    public function channelFor(string $platform): ?array
    {
        $id = $this->map()[$platform] ?? null;

        return $id ? collect($this->channels())->first(fn ($c) => $c['id'] === $id && $c['usable']) : null;
    }

    public function platformForService(string $service): ?string
    {
        $plain = fn (string $s) => strtolower(str_replace([' ', '-', '_'], '', $s));
        foreach (self::SERVICES as $platform => $names) {
            if (in_array($plain($service), array_map($plain, $names), true)) {
                return $platform;
            }
        }

        return null;
    }

    /**
     * Check the key with Buffer, keep it (encrypted) and read the channels. Throws with plain words if Buffer says no;
     * nothing is saved then.
     */
    public function connect(string $key): void
    {
        $orgs = $this->query('query { account { organizations { id name } } }', [], $key)['account']['organizations'] ?? [];
        if ($orgs === []) {
            throw new RuntimeException('Buffer did not show any organisation for this key.');
        }
        Setting::set(self::API_KEY, Crypt::encryptString($key));
        Setting::set(self::ORG_NAME, (string) ($orgs[0]['name'] ?? ''));
        Setting::clear(self::BROKEN, 'global', null);
        $this->refresh($orgs);
    }

    /** Read the channels again (after a channel is added in Buffer) and match new ones; keeps choices already made. */
    public function refresh(?array $orgs = null): void
    {
        $orgs ??= $this->query('query { account { organizations { id name } } }')['account']['organizations'] ?? [];
        $channels = [];
        foreach ($orgs as $org) {
            $rows = $this->query('query Channels($org: OrganizationId!) { channels(input: { organizationId: $org }) { id name displayName service isDisconnected isLocked } }',
                ['org' => $org['id']])['channels'] ?? [];
            foreach ($rows as $c) {
                $service = is_array($c['service'] ?? null) ? (string) ($c['service'][0] ?? '') : (string) ($c['service'] ?? '');
                $channels[] = [
                    'id' => (string) $c['id'],
                    'name' => (string) (($c['displayName'] ?? null) ?: ($c['name'] ?? '')),
                    'service' => $service,
                    'platform' => $this->platformForService($service),
                    'usable' => empty($c['isDisconnected']) && empty($c['isLocked']),
                ];
            }
        }
        Setting::set(self::CHANNELS, json_encode($channels));

        // Keep each choice that still exists and is usable; give every other platform its first usable channel.
        $map = [];
        foreach (array_keys(self::SERVICES) as $platform) {
            $current = $this->map()[$platform] ?? null;
            $still = collect($channels)->first(fn ($c) => $c['id'] === $current && $c['platform'] === $platform && $c['usable']);
            $first = collect($channels)->first(fn ($c) => $c['platform'] === $platform && $c['usable']);
            if ($pick = $still ?? $first) {
                $map[$platform] = $pick['id'];
            }
        }
        Setting::set(self::MAP, json_encode($map));
        $this->readEnums();
    }

    public function choose(string $platform, string $channelId): void
    {
        $channel = collect($this->channels())->first(fn ($c) => $c['id'] === $channelId && $c['platform'] === $platform && $c['usable']);
        if (! $channel) {
            throw new RuntimeException('That Buffer channel cannot post to '.(SocialPost::PLATFORMS[$platform][0] ?? $platform).'.');
        }
        Setting::set(self::MAP, json_encode(array_merge($this->map(), [$platform => $channelId])));
    }

    public function disconnect(): void
    {
        foreach ([self::API_KEY, self::ORG_NAME, self::CHANNELS, self::MAP, self::ENUMS, self::BROKEN] as $key) {
            Setting::clear($key, 'global', null);
        }
    }

    /**
     * Hand one post to Buffer to publish now on the platform's channel.
     *
     * @return string Buffer's post id
     *
     * @throws RuntimeException with a plain-words reason
     */
    public function publish(SocialPost $post, string $platform): string
    {
        if (! $this->connected()) {
            throw new RuntimeException('Buffer is not connected.');
        }
        $channel = $this->channelFor($platform);
        if (! $channel) {
            throw new RuntimeException('No Buffer channel is set for '.(SocialPost::PLATFORMS[$platform][0] ?? $platform).'. Pick one under Buffer.');
        }

        $image = null;
        if ($platform === 'instagram') {
            $image = app(SocialImage::class)->instagramJpeg($post);   // throws "Instagram needs a picture" when there is none
        } elseif ($post->imageUrl()) {
            $image = url($post->imageUrl());
        }

        $input = [
            'channelId' => $channel['id'],
            'text' => $this->text($post, $platform),
            'schedulingType' => $this->enum('SchedulingType', ['^automatic$'], 'automatic'),
            'mode' => $this->enum('ShareMode', ['^shareNow$', 'now'], 'shareNow'),
            'needsApproval' => false,
            'assets' => $image ? [['image' => ['url' => $image]]] : [],
            'source' => '1callfix',
        ];
        if ($metadata = $this->metadata($post, $platform)) {
            $input['metadata'] = $metadata;
        }

        $res = $this->query('mutation CreatePost($input: CreatePostInput!) { createPost(input: $input) {
            ... on PostActionSuccess { post { id status externalLink } }
            ... on MutationError { message } } }', ['input' => $input])['createPost'] ?? [];

        $id = (string) ($res['post']['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Buffer said no: '.Str::limit((string) ($res['message'] ?? 'no reason given'), 220));
        }

        return $id;
    }

    /**
     * Ask Buffer how a post it took is doing.
     *
     * @return array{status: string, url: ?string, error: ?string} status = sent | error | waiting
     */
    public function status(string $postId): array
    {
        $post = $this->query('query Post($id: PostId!) { post(input: { id: $id }) { id status externalLink error { message } } }', ['id' => $postId])['post'] ?? null;
        if (! $post) {
            return ['status' => 'error', 'url' => null, 'error' => 'Buffer no longer has this post.'];
        }
        $status = strtolower((string) ($post['status'] ?? ''));

        return [
            'status' => $status === 'sent' ? 'sent' : ($status === 'error' ? 'error' : 'waiting'),
            'url' => $post['externalLink'] ?? null,
            'error' => $post['error']['message'] ?? null,
        ];
    }

    /** Caption + Telugu + hashtags + tracked link, shortened to what the place takes (the link is kept whole). */
    public function text(SocialPost $post, string $platform): string
    {
        // Google Business carries the link on its "Learn more" button, so the words stay clean there.
        $link = $platform === 'google_business' ? null : $post->trackedLink($platform);
        $words = implode("\n\n", array_filter([trim((string) $post->caption_en), trim((string) $post->caption_te), trim((string) $post->hashtags)]));
        $limit = self::TEXT_LIMITS[$platform] ?? null;
        if ($limit) {
            // X counts every link as 23 characters.
            $room = $limit - ($link ? ($platform === 'x' ? 23 : mb_strlen($link)) + 2 : 0);
            $words = Str::limit($words, max(20, $room - 1), '…');
        }

        return implode("\n\n", array_filter([$words, $link]));
    }

    private function metadata(SocialPost $post, string $platform): ?array
    {
        return match ($platform) {
            'facebook' => ['facebook' => ['type' => $this->enum('PostTypeFacebook', ['^post$'], 'post')]],
            'instagram' => ['instagram' => ['type' => $this->enum('PostType', ['^post$'], 'post'), 'shouldShareToFeed' => true]],
            'google_business' => ['google' => [
                'type' => $this->enum('PostTypeGoogleBusiness', ['what', '^post$', 'standard', 'update'], 'whatsNew'),
                'detailsWhatsNew' => ($link = $post->trackedLink('google_business'))
                    ? ['button' => $this->enum('GoogleBusinessPostActionType', ['learn', 'more'], 'learnMore'), 'link' => $link]
                    : ['button' => $this->enum('GoogleBusinessPostActionType', ['^none$'], 'none')],
            ]],
            default => null,
        };
    }

    /** The first value of a Buffer enum matching one of the patterns (in order), from what Connect read; else $default. */
    public function enum(string $name, array $patterns, string $default): string
    {
        $values = json_decode((string) Setting::get(self::ENUMS, ''), true)[$name] ?? [];
        foreach ($patterns as $pattern) {
            foreach ($values as $value) {
                if (preg_match('/'.$pattern.'/i', (string) $value)) {
                    return (string) $value;
                }
            }
        }

        return $default;
    }

    private function readEnums(): void
    {
        try {
            $parts = array_map(fn ($n) => "{$n}: __type(name: \"{$n}\") { enumValues { name } }", self::ENUM_NAMES);
            $data = $this->query('query { '.implode(' ', $parts).' }');
            $enums = [];
            foreach (self::ENUM_NAMES as $n) {
                $enums[$n] = array_map(fn ($v) => (string) $v['name'], $data[$n]['enumValues'] ?? []);
            }
            Setting::set(self::ENUMS, json_encode($enums));
        } catch (\Throwable) {
            // Introspection switched off: the defaults stand.
        }
    }

    /** One GraphQL call; errors become plain-words exceptions; a refused key marks the connection broken. */
    private function query(string $query, array $variables = [], ?string $key = null): array
    {
        $usingSaved = $key === null;
        $key ??= $this->apiKey();
        if (! $key) {
            throw new RuntimeException('Buffer is not connected.');
        }

        $res = Http::timeout(60)->acceptJson()->withToken($key)
            ->post(self::ENDPOINT, array_filter(['query' => $query, 'variables' => $variables ?: null]));

        if (in_array($res->status(), [401, 403], true)) {
            if ($usingSaved) {
                Setting::set(self::BROKEN, 'Buffer refused the API key (HTTP '.$res->status().').');
            }
            throw new RuntimeException('Buffer did not accept the API key. Make a new key in Buffer → Settings → API and paste it again.');
        }
        if ($res->status() === 429) {
            throw new RuntimeException('Buffer says too many requests for now. Try again in a few minutes.');
        }
        $errors = (array) $res->json('errors', []);
        if (! $res->successful() || $errors !== []) {
            $message = (string) ($errors[0]['message'] ?? 'Buffer said no (HTTP '.$res->status().').');
            if (preg_match('/unauthori[sz]ed|unauthenticated|invalid (api )?key/i', $message) && $usingSaved) {
                Setting::set(self::BROKEN, Str::limit($message, 180));
            }
            throw new RuntimeException(Str::limit($message, 250));
        }

        return (array) $res->json('data', []);
    }
}
