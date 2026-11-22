<?php

namespace App\Services\Marketing;

use App\Models\Setting;
use App\Models\SocialPost;
use RuntimeException;

/**
 * How each place posts (owner 2026-11-22: "we will on/off direct or buffer from admin"). One choice per platform,
 * Super Admin only:
 *   direct = our own Facebook connection (Facebook, Instagram only)
 *   buffer = through the company's Buffer account
 *   manual = the Share button and Done ✓
 * One route per platform, so a post can never go out twice (once direct and once through Buffer).
 */
class PostingRoutes
{
    public const PREFIX = 'marketing.route.';

    public const LABELS = ['direct' => 'Direct', 'buffer' => 'Buffer', 'manual' => 'By hand'];

    public function __construct(private MetaGraph $meta, private BufferApi $buffer) {}

    /** @return list<string> the routes a platform can use, in display order */
    public static function options(string $platform): array
    {
        return match (true) {
            in_array($platform, ['facebook', 'instagram'], true) => ['direct', 'buffer', 'manual'],
            isset(BufferApi::SERVICES[$platform]) => ['buffer', 'manual'],
            default => ['manual'],   // WhatsApp Status: no app may post there
        };
    }

    public function route(string $platform): string
    {
        $saved = (string) Setting::get(self::PREFIX.$platform, '');
        if (in_array($saved, self::options($platform), true)) {
            return $saved;
        }

        // Not chosen yet: Facebook / Instagram keep the behaviour from before this screen (direct unless switched off).
        return in_array($platform, ['facebook', 'instagram'], true) && $this->meta->autopostOn() ? 'direct' : 'manual';
    }

    /** Null = ready to post by itself; otherwise what is missing, in plain words. */
    public function missing(string $platform): ?string
    {
        return match ($this->route($platform)) {
            'direct' => ! $this->meta->connected() ? 'Connect Facebook below.'
                : ($platform === 'instagram' && ! Setting::get(MetaGraph::IG_ID) ? 'No Instagram is linked to the Facebook Page.' : null),
            'buffer' => ! $this->buffer->connected() ? 'Connect Buffer below.'
                : (! $this->buffer->channelFor($platform) ? 'Add a '.(SocialPost::PLATFORMS[$platform][0] ?? $platform).' channel in Buffer, then press Refresh channels.' : null),
            default => null,
        };
    }

    /** Platforms that post by themselves right now. */
    public function autoPlatforms(): array
    {
        return array_values(array_filter(array_keys(SocialPost::PLATFORMS),
            fn ($p) => $this->route($p) !== 'manual' && $this->missing($p) === null));
    }

    /** Platforms the post editor offers: the usual four, plus any Buffer-only place that posts by itself. */
    public function editorPlatforms(): array
    {
        $auto = $this->autoPlatforms();

        return array_values(array_filter(array_keys(SocialPost::PLATFORMS),
            fn ($p) => in_array($p, SocialPost::CORE_PLATFORMS, true) || in_array($p, $auto, true)));
    }

    /**
     * Publish one post on one platform the way the Super Admin chose.
     *
     * @return array{via: string, status: string, url: ?string, external_id: ?string} status posted (direct) or sent (Buffer took it)
     */
    public function publish(SocialPost $post, string $platform): array
    {
        return match ($this->route($platform)) {
            'direct' => ['via' => 'direct', 'status' => 'posted', 'url' => $this->meta->publish($post, $platform), 'external_id' => null],
            'buffer' => ['via' => 'buffer', 'status' => 'sent', 'url' => null, 'external_id' => $this->buffer->publish($post, $platform)],
            default => throw new RuntimeException((SocialPost::PLATFORMS[$platform][0] ?? $platform).' is set to By hand.'),
        };
    }
}
