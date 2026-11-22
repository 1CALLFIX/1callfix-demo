<?php

namespace App\Services\Marketing;

use App\Models\Setting;
use App\Models\SocialMediaLink;
use App\Models\SocialPost;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Automatic posting to the 1CallFix Facebook Page and its linked Instagram (owner 2026-11-22), through the company's
 * own Meta developer app (Development mode: only the app's admins can connect, which is all we need).
 *
 * The Super Admin types the app's ID and secret in Admin → Marketing → Social posts (the secret is stored encrypted and
 * never shown again), presses Connect, approves once on Facebook; we keep the Page's access token (from a long-lived
 * login it does not expire) encrypted on social_media_links (facebook row). Page / Instagram ids are plain settings.
 */
class MetaGraph
{
    public const APP_ID = 'marketing.meta.app_id';
    public const APP_SECRET = 'marketing.meta.app_secret';     // Crypt::encryptString
    public const PAGE_ID = 'marketing.meta.page_id';
    public const PAGE_NAME = 'marketing.meta.page_name';
    public const IG_ID = 'marketing.meta.ig_user_id';
    public const IG_USERNAME = 'marketing.meta.ig_username';
    public const BROKEN = 'marketing.meta.broken';              // why the last call said the connection no longer works
    public const AUTOPOST = 'marketing.meta.autopost';          // '0' = keep connected but post by hand

    /** What we ask Facebook for: list and post to the Page, and publish on its Instagram. */
    public const SCOPES = [
        'pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'business_management',
        'instagram_basic', 'instagram_content_publish',
    ];

    public function version(): string
    {
        return (string) config('services.meta.graph_version', 'v27.0');
    }

    public function appId(): ?string
    {
        $id = trim((string) Setting::get(self::APP_ID, ''));

        return $id !== '' ? $id : null;
    }

    public function appSecret(): ?string
    {
        $stored = (string) Setting::get(self::APP_SECRET, '');
        if ($stored === '') {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return null;
        }
    }

    public function hasApp(): bool
    {
        return $this->appId() !== null && $this->appSecret() !== null;
    }

    public function pageToken(): ?string
    {
        $token = SocialMediaLink::where('platform', 'facebook')->first()?->access_token;

        return $token ? (string) $token : null;
    }

    /** Connected = a Page token and a Page id, and no call has since said the token stopped working. */
    public function connected(): bool
    {
        return $this->hasApp() && $this->pageToken() !== null && Setting::get(self::PAGE_ID) && ! Setting::get(self::BROKEN);
    }

    public function autopostOn(): bool
    {
        return (string) Setting::get(self::AUTOPOST, '1') !== '0';
    }

    /** Which platforms we can post to by ourselves right now. */
    public function autoPlatforms(): array
    {
        if (! $this->connected() || ! $this->autopostOn()) {
            return [];
        }

        return Setting::get(self::IG_ID) ? ['facebook', 'instagram'] : ['facebook'];
    }

    /**
     * Always the site's main address (APP_URL, https, no www), whatever address the Super Admin opened the admin on:
     * Facebook only accepts the exact return address saved in the Meta app, and the site also answers on http:// and
     * www. (owner 2026-11-22: "URL Blocked").
     */
    public function redirectUri(): string
    {
        return self::mainRoot().route('admin.marketing.meta.callback', [], false);
    }

    public static function mainRoot(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public function loginUrl(string $state): string
    {
        return 'https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query([
            'client_id' => $this->appId(),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'scope' => implode(',', self::SCOPES),
            'response_type' => 'code',
        ]);
    }

    /**
     * Code from the Facebook login → long-lived user token → the Pages this person runs, each with its own token and
     * linked Instagram. Page tokens made from a long-lived user token do not expire.
     *
     * @return list<array{id: string, name: string, token: string, ig_id: ?string, ig_username: ?string}>
     */
    public function pagesFromCode(string $code): array
    {
        $short = $this->call('get', 'oauth/access_token', [
            'client_id' => $this->appId(), 'client_secret' => $this->appSecret(),
            'redirect_uri' => $this->redirectUri(), 'code' => $code,
        ], null)['access_token'] ?? null;
        if (! $short) {
            throw new RuntimeException('Facebook did not return a login.');
        }
        $long = $this->call('get', 'oauth/access_token', [
            'grant_type' => 'fb_exchange_token', 'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(), 'fb_exchange_token' => $short,
        ], null)['access_token'] ?? $short;

        $pages = $this->call('get', 'me/accounts', [
            'fields' => 'id,name,access_token,instagram_business_account{id,username}', 'limit' => 50,
        ], $long)['data'] ?? [];

        return array_values(array_map(fn (array $p) => [
            'id' => (string) $p['id'],
            'name' => (string) ($p['name'] ?? ''),
            'token' => (string) ($p['access_token'] ?? ''),
            'ig_id' => isset($p['instagram_business_account']['id']) ? (string) $p['instagram_business_account']['id'] : null,
            'ig_username' => $p['instagram_business_account']['username'] ?? null,
        ], array_filter($pages, fn ($p) => ! empty($p['access_token']))));
    }

    /** @param  array{id: string, name: string, token: string, ig_id: ?string, ig_username: ?string}  $page */
    public function savePage(array $page): void
    {
        $link = SocialMediaLink::firstOrNew(['platform' => 'facebook']);
        $link->access_token = $page['token'];
        $link->connected_at = now();
        $link->token_expiry = null;
        $link->save();
        SocialMediaLink::forgetCache();

        Setting::set(self::PAGE_ID, $page['id']);
        Setting::set(self::PAGE_NAME, $page['name']);
        $page['ig_id'] ? Setting::set(self::IG_ID, $page['ig_id']) : Setting::clear(self::IG_ID, 'global', null);
        $page['ig_username'] ? Setting::set(self::IG_USERNAME, (string) $page['ig_username']) : Setting::clear(self::IG_USERNAME, 'global', null);
        Setting::clear(self::BROKEN, 'global', null);
    }

    public function disconnect(): void
    {
        $link = SocialMediaLink::where('platform', 'facebook')->first();
        if ($link) {
            $link->access_token = null;
            $link->connected_at = null;
            $link->save();
        }
        foreach ([self::PAGE_ID, self::PAGE_NAME, self::IG_ID, self::IG_USERNAME, self::BROKEN] as $key) {
            Setting::clear($key, 'global', null);
        }
    }

    /**
     * Publish one post on Facebook or Instagram. Returns the post's web address.
     *
     * @throws RuntimeException with a plain-words reason
     */
    public function publish(SocialPost $post, string $platform): ?string
    {
        if (! $this->connected()) {
            throw new RuntimeException('Facebook is not connected.');
        }
        $token = (string) $this->pageToken();
        $text = $post->shareText($platform);

        if ($platform === 'facebook') {
            $page = (string) Setting::get(self::PAGE_ID);
            $image = $post->imageUrl() ? url($post->imageUrl()) : null;
            $res = $image
                ? $this->call('post', "{$page}/photos", ['url' => $image, 'caption' => $text, 'published' => 'true'], $token)
                : $this->call('post', "{$page}/feed", array_filter(['message' => $text, 'link' => $post->trackedLink('facebook')]), $token);
            $id = (string) ($res['post_id'] ?? $res['id'] ?? '');

            return $id !== '' ? 'https://www.facebook.com/'.$id : null;
        }

        if ($platform === 'instagram') {
            $ig = (string) Setting::get(self::IG_ID);
            if ($ig === '') {
                throw new RuntimeException('No Instagram account is linked to the Facebook Page.');
            }
            $image = app(SocialImage::class)->instagramJpeg($post);
            $container = $this->call('post', "{$ig}/media", ['image_url' => $image, 'caption' => Str::limit($text, 2150, '')], $token)['id'] ?? null;
            if (! $container) {
                throw new RuntimeException('Instagram did not accept the picture.');
            }
            $mediaId = $this->call('post', "{$ig}/media_publish", ['creation_id' => $container], $token)['id'] ?? null;
            if (! $mediaId) {
                throw new RuntimeException('Instagram did not publish the post.');
            }
            try {
                return $this->call('get', (string) $mediaId, ['fields' => 'permalink'], $token)['permalink'] ?? null;
            } catch (\Throwable) {
                return null;
            }
        }

        throw new RuntimeException('Automatic posting is not available for '.$platform.'.');
    }

    /** One Graph API call; errors become plain-words exceptions, and an expired / removed login marks the connection broken. */
    private function call(string $method, string $path, array $params, ?string $token): array
    {
        if ($token !== null) {
            $params['access_token'] = $token;
            if ($secret = $this->appSecret()) {
                $params['appsecret_proof'] = hash_hmac('sha256', $token, $secret);
            }
        }
        $url = 'https://graph.facebook.com/'.$this->version().'/'.ltrim($path, '/');

        /** @var PendingRequest $http */
        $http = Http::timeout(60)->acceptJson();
        /** @var Response $res */
        $res = $method === 'post' ? $http->asForm()->post($url, $params) : $http->get($url, $params);

        if ($res->successful()) {
            return (array) $res->json();
        }

        $error = (array) $res->json('error', []);
        $message = (string) ($error['error_user_msg'] ?? $error['message'] ?? 'Facebook said no (HTTP '.$res->status().').');
        if ((int) ($error['code'] ?? 0) === 190 && $token !== null) {
            Setting::set(self::BROKEN, Str::limit($message, 180));
            throw new RuntimeException('The Facebook connection stopped working. Press Connect again. ('.Str::limit($message, 120).')');
        }

        throw new RuntimeException(Str::limit($message, 250));
    }
}
