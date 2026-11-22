<?php

namespace App\Services\Marketing;

use App\Models\SocialPost;
use App\Models\SocialPostDelivery;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Posts scheduled social posts to Facebook and Instagram by themselves once their time comes (owner 2026-11-22), via
 * marketing:posts-due every 15 minutes. Each post goes out at most once per platform: the delivery row is claimed
 * (pending → posting) before Facebook is called, and a failure is never retried by itself (a retried post could appear
 * twice); the screen shows the reason with a "Try again" button. Google Business and WhatsApp stay one tap.
 */
class AutoPoster
{
    public function __construct(private MetaGraph $meta) {}

    /** @return array{posted: int, failed: int} */
    public function publishDue(): array
    {
        $platforms = $this->meta->autoPlatforms();
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
                        continue;   // posted, posting, failed (waits for Try again) or skipped
                    }
                    $outcome = $this->publishOne($post, $platform, null);
                    $outcome === true ? $result['posted']++ : $result['failed']++;
                }
            });

        return $result;
    }

    /**
     * Claim and post one platform. True = posted, otherwise the reason. $by = the person who pressed Post now / Try
     * again (null for the schedule).
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

        try {
            $url = $this->meta->publish($post, $platform);
        } catch (\Throwable $e) {
            $reason = Str::limit($e->getMessage(), 280);
            SocialPostDelivery::whereKey($delivery->id)->update(['status' => 'failed', 'error' => $reason, 'updated_at' => now()]);
            Log::warning('Social post could not be posted automatically.', ['post' => $post->id, 'platform' => $platform, 'error' => $reason]);

            return $reason;
        }

        SocialPostDelivery::whereKey($delivery->id)->update([
            'status' => 'posted', 'posted_at' => now(), 'posted_by' => $by, 'post_url' => $url ? Str::limit($url, 500, '') : null,
            'error' => null, 'updated_at' => now(),
        ]);
        $post->load('deliveries');
        if (collect($post->platforms)->every(fn ($p) => $post->isPostedOn($p))) {
            $post->forceFill(['status' => 'posted'])->save();
        }
        $who = $by ? User::find($by) : null;
        ActivityLogger::logModel($who, $post, 'Social post posted '.($who ? 'by Post now' : 'automatically').' on '.(SocialPost::PLATFORMS[$platform][0] ?? $platform), ['post_url' => $url]);

        return true;
    }
}
