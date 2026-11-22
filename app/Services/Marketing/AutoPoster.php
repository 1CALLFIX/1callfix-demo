<?php

namespace App\Services\Marketing;

use App\Models\SocialPost;
use App\Models\SocialPostDelivery;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Posts scheduled social posts by themselves once their time comes (owner 2026-11-22), via marketing:posts-due every
 * 5 minutes, on each platform the way the Super Admin chose (PostingRoutes: our own Facebook connection, or Buffer).
 * Each post goes out at most once per platform: the delivery row is claimed (pending → posting) before anything is
 * called, and a failure is never retried by itself (a retried post could appear twice); the screen shows the reason
 * with a "Try again" button. A post handed to Buffer is "sent" until Buffer says it went live (or failed): syncBuffer()
 * asks each run. WhatsApp Status stays one tap.
 */
class AutoPoster
{
    public function __construct(private PostingRoutes $routes, private BufferApi $buffer) {}

    /** @return array{posted: int, failed: int} */
    public function publishDue(): array
    {
        $platforms = $this->routes->autoPlatforms();
        $result = ['posted' => 0, 'failed' => 0];
        if ($platforms === []) {
            return $result;
        }

        SocialPost::query()->where('status', 'scheduled')->where('scheduled_for', '<=', now())
            ->where('scheduled_for', '>=', now()->subDay())   // never a backlog of old posts after a long pause
            ->with('deliveries')->orderBy('scheduled_for')->limit(20)->get()
            ->each(function (SocialPost $post) use ($platforms, &$result) {
                foreach (array_intersect($post->platforms ?? [], $platforms) as $platform) {
                    $existing = $post->deliveries->firstWhere('platform', $platform);
                    if ($existing && $existing->status !== 'pending') {
                        continue;   // posted, posting, sent, failed (waits for Try again) or skipped
                    }
                    $outcome = $this->publishOne($post, $platform, null);
                    $outcome === true ? $result['posted']++ : $result['failed']++;
                }
            });

        return $result;
    }

    /**
     * Claim and post one platform. True = posted (or handed to Buffer), otherwise the reason. $by = the person who
     * pressed Post now / Try again (null for the schedule).
     */
    public function publishOne(SocialPost $post, string $platform, ?int $by): true|string
    {
        $delivery = SocialPostDelivery::firstOrCreate(['social_post_id' => $post->id, 'platform' => $platform], ['status' => 'pending']);
        // A "posting" row older than 30 minutes is a run that died half way: Try again may take it over.
        $claimed = SocialPostDelivery::whereKey($delivery->id)
            ->where(fn ($q) => $q->whereIn('status', ['pending', 'failed'])
                ->orWhere(fn ($q) => $q->where('status', 'posting')->where('updated_at', '<', now()->subMinutes(30))))
            ->update(['status' => 'posting', 'error' => null, 'updated_at' => now()]);
        if ($claimed !== 1) {
            return 'Already being posted.';
        }

        $via = $this->routes->route($platform);
        try {
            $out = $this->routes->publish($post, $platform);
        } catch (\Throwable $e) {
            $reason = Str::limit($e->getMessage(), 280);
            SocialPostDelivery::whereKey($delivery->id)->update(['status' => 'failed', 'via' => $via, 'error' => $reason, 'updated_at' => now()]);
            Log::warning('Social post could not be posted automatically.', ['post' => $post->id, 'platform' => $platform, 'via' => $via, 'error' => $reason]);

            return $reason;
        }

        SocialPostDelivery::whereKey($delivery->id)->update([
            'status' => $out['status'], 'via' => $out['via'], 'external_id' => $out['external_id'],
            'posted_at' => $out['status'] === 'posted' ? now() : null, 'posted_by' => $by,
            'post_url' => $out['url'] ? Str::limit($out['url'], 500, '') : null, 'error' => null, 'updated_at' => now(),
        ]);
        $this->closeIfDone($post);
        $who = $by ? User::find($by) : null;
        $label = SocialPost::PLATFORMS[$platform][0] ?? $platform;
        ActivityLogger::logModel($who, $post, ($out['via'] === 'buffer' ? 'Social post sent through Buffer ' : 'Social post posted ')
            .($who ? 'by Post now' : 'automatically').' on '.$label, ['post_url' => $out['url'], 'buffer_post' => $out['external_id']]);

        return true;
    }

    /**
     * Ask Buffer about posts it took: live → posted with its address; error → failed with Buffer's reason (waits for
     * Try again). Looks back 3 days; quiet when Buffer is not connected.
     *
     * @return array{posted: int, failed: int}
     */
    public function syncBuffer(): array
    {
        $result = ['posted' => 0, 'failed' => 0];
        if (! $this->buffer->connected()) {
            return $result;
        }

        SocialPostDelivery::query()->where('via', 'buffer')->where('status', 'sent')->whereNotNull('external_id')
            ->where('updated_at', '>=', now()->subDays(3))->with('post.deliveries')->orderBy('id')->limit(30)->get()
            ->each(function (SocialPostDelivery $d) use (&$result) {
                try {
                    $state = $this->buffer->status((string) $d->external_id);
                } catch (\Throwable $e) {
                    Log::info('Buffer post status not read.', ['delivery' => $d->id, 'error' => $e->getMessage()]);

                    return;
                }
                if ($state['status'] === 'sent') {
                    $d->forceFill(['status' => 'posted', 'posted_at' => now(), 'post_url' => $state['url'] ? Str::limit($state['url'], 500, '') : null])->save();
                    $this->closeIfDone($d->post);
                    $result['posted']++;
                } elseif ($state['status'] === 'error') {
                    $reason = Str::limit('Buffer could not post it: '.($state['error'] ?: 'no reason given'), 280);
                    $d->forceFill(['status' => 'failed', 'error' => $reason])->save();
                    Log::warning('Social post failed at Buffer.', ['post' => $d->social_post_id, 'platform' => $d->platform, 'error' => $reason]);
                    $result['failed']++;
                }
            });

        return $result;
    }

    private function closeIfDone(?SocialPost $post): void
    {
        if (! $post) {
            return;
        }
        $post->load('deliveries');
        if ($post->status === 'scheduled' && collect($post->platforms)->every(fn ($p) => $post->isPostedOn($p))) {
            $post->forceFill(['status' => 'posted'])->save();
        }
    }
}
