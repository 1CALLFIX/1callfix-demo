<?php

namespace App\Models;

use App\Support\SocialPlatforms;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SocialMediaLink extends Model
{
    protected $fillable = ['platform', 'profile_url'];

    // Future OAuth auto-posting fields. Encrypted at rest and hidden from
    // serialisation now so no later module can leak a token by accident.
    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'token_expiry' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    /**
     * Footer-ready links: only platforms with a real URL, in registry order.
     * A row with a blank URL falls back to the legacy Setting key, and a
     * platform with neither is omitted — never an empty/dead icon.
     *
     * @return list<array{platform:string,label:string,path:string,url:string}>
     */
    public static function footerLinks(): array
    {
        $rows = Cache::remember('social_media_links.footer', 3600, fn () => static::query()->pluck('profile_url', 'platform')->all());

        $links = [];
        foreach (SocialPlatforms::ALL as $key => $meta) {
            $url = trim((string) ($rows[$key] ?? ''));
            if ($url === '') {
                $url = trim((string) Setting::get($meta['legacy_setting'], ''));
            }
            // Defence in depth: only ever emit http(s) hrefs.
            if ($url !== '' && preg_match('#^https?://#i', $url)) {
                $links[] = ['platform' => $key, 'label' => $meta['label'], 'path' => $meta['path'], 'url' => $url];
            }
        }

        return $links;
    }

    public static function forgetCache(): void
    {
        Cache::forget('social_media_links.footer');
    }
}
