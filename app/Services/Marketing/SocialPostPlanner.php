<?php

namespace App\Services\Marketing;

use App\Models\Setting;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\TimezoneResolver;
use Carbon\CarbonImmutable;

/**
 * Social posts (owner 2026-11-21): settings, ready-made drafts, "Fill my next 7 days", and the module auto-skip.
 * Nothing here ever posts by itself: drafts wait for the team to look and tap Schedule; sharing is one tap by a person
 * until automatic posting is approved by Meta / Google.
 */
class SocialPostPlanner
{
    public const PLATFORMS_SETTING = 'marketing.platforms';

    public const REMINDER_SETTING = 'marketing.reminder_time';

    public const POST_TIME_SETTING = 'marketing.post_time';

    public function __construct(private PostIdeas $ideas, private ModuleGate $gate) {}

    /** @return list<string> */
    public static function platforms(): array
    {
        $saved = array_values(array_intersect(explode(',', (string) Setting::get(self::PLATFORMS_SETTING)), array_keys(SocialPost::PLATFORMS)));

        return $saved !== [] ? $saved : ['facebook', 'instagram', 'google_business', 'whatsapp'];
    }

    public static function reminderTime(): string
    {
        $t = (string) Setting::get(self::REMINDER_SETTING);

        return preg_match('/^\d{2}:\d{2}$/', $t) ? $t : '09:30';
    }

    public static function postTime(): string
    {
        $t = (string) Setting::get(self::POST_TIME_SETTING);

        return preg_match('/^\d{2}:\d{2}$/', $t) ? $t : '10:00';
    }

    public static function zone(): string
    {
        return app(TimezoneResolver::class)->platformTimezone();
    }

    /** Local day + the posting time → UTC timestamp. */
    public static function at(CarbonImmutable $localDay): CarbonImmutable
    {
        [$h, $m] = explode(':', self::postTime());

        return $localDay->setTimezone(self::zone())->setTime((int) $h, (int) $m)->utc();
    }

    /** A draft from a ready-made idea. */
    public function fromIdea(array $idea, User $by, ?CarbonImmutable $when = null, string $status = 'draft'): SocialPost
    {
        $post = SocialPost::create([
            'title' => $idea['title'], 'caption_en' => $idea['caption_en'], 'caption_te' => $idea['caption_te'],
            'hashtags' => $idea['hashtags'], 'image_path' => $idea['image_path'], 'link_url' => $idea['link_url'],
            'module' => $idea['module'], 'city_id' => $idea['city_id'], 'platforms' => self::platforms(),
            'scheduled_for' => $when, 'status' => $status, 'source' => $idea['source'], 'source_ref' => $idea['source_ref'],
            'created_by' => $by->id,
        ] + $this->place($idea['city_id']));
        ActivityLogger::logModel($by, $post, 'Social post drafted from a ready-made idea', ['source' => $idea['source']]);

        return $post;
    }

    /**
     * One draft per day for the next $days days that have no post yet, rotating across modules so one module does not
     * fill the week. Returns how many drafts were made (0 when there are no ideas left).
     */
    public function fillDays(User $by, int $days = 7, ?int $cityId = null): int
    {
        $ideas = collect($this->ideas->all($cityId, 40));
        if ($ideas->isEmpty()) {
            return 0;
        }
        // Rotate: one idea per module/source in turn.
        $queue = $ideas->groupBy(fn ($i) => $i['module'] ?? $i['source'])->values()->map->values();
        $ordered = collect();
        for ($round = 0; $ordered->count() < $ideas->count(); $round++) {
            foreach ($queue as $group) {
                if (isset($group[$round])) {
                    $ordered->push($group[$round]);
                }
            }
        }

        $made = 0;
        $today = CarbonImmutable::now(self::zone())->startOfDay();
        for ($d = 1; $d <= $days && $ordered->isNotEmpty(); $d++) {
            $day = $today->addDays($d);
            $busy = SocialPost::query()->whereIn('status', ['draft', 'scheduled', 'posted'])
                ->whereBetween('scheduled_for', [$day->utc(), $day->endOfDay()->utc()])->exists();
            if ($busy) {
                continue;
            }
            $this->fromIdea($ordered->shift(), $by, self::at($day));
            $made++;
        }

        return $made;
    }

    /** Marks upcoming posts whose module is switched off as skipped, with the reason. Returns how many. */
    public function skipSwitchedOff(): int
    {
        $skipped = 0;
        SocialPost::query()->whereIn('status', ['draft', 'scheduled'])->whereNotNull('module')
            ->where(fn ($q) => $q->whereNull('scheduled_for')->orWhere('scheduled_for', '>=', now()->subDay()))
            ->each(function (SocialPost $post) use (&$skipped) {
                if ($reason = $this->gate->offReason($post->module, $post->city_id)) {
                    $post->forceFill(['status' => 'skipped', 'skip_reason' => $reason])->save();
                    $skipped++;
                }
            });

        return $skipped;
    }

    /** @return array{franchise_id: ?int, country_id: ?int} */
    private function place(?int $cityId): array
    {
        if (! $cityId) {
            return ['franchise_id' => null, 'country_id' => null];
        }
        $city = \App\Models\City::find($cityId, ['id', 'country_id']);
        $franchises = \App\Models\Franchise::where('city_id', $cityId)->limit(2)->pluck('id');

        return ['franchise_id' => $franchises->count() === 1 ? $franchises->first() : null, 'country_id' => $city?->country_id];
    }
}
