<?php

namespace App\Console\Commands;

use App\Models\MarketingContact;
use App\Models\SocialPost;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use App\Notifications\MarketingPostsReady;
use App\Notifications\Support\ChannelResolver;
use App\Services\Marketing\SocialPostPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Digital Marketing (owner 2026-11-21), every 15 minutes:
 *  1. posts for a switched-off module are skipped (with the reason), so no ad goes out for it;
 *  2. at the reminder time (Super Admin setting, default 09:30) the marketing team gets "N posts ready to share
 *     today" by email + push, and at 18:00 a nudge if some are still not shared; each once a day;
 *  3. once a day, contacts whose mobile has signed up are marked Joined.
 * Never posts anything itself.
 */
class MarketingPostsDue extends Command
{
    protected $signature = 'marketing:posts-due';

    protected $description = 'Skips posts for switched-off modules and reminds the marketing team of today\'s posts';

    public function handle(SocialPostPlanner $planner): int
    {
        $skipped = $planner->skipSwitchedOff();
        $now = CarbonImmutable::now(SocialPostPlanner::zone());
        $day = $now->toDateString();

        if (Cache::add("marketing.joined-sync.{$day}", true, now()->addDay())) {
            MarketingContact::syncJoined();
        }

        $due = self::dueToday()->get()->filter(fn (SocialPost $p) => collect($p->platforms)->contains(fn ($pl) => ! $p->isPostedOn($pl)))->count();
        $sent = 0;
        if ($due > 0 && $now->format('H:i') >= SocialPostPlanner::reminderTime() && Cache::add("marketing.reminded.{$day}", true, now()->addDay())) {
            $sent = $this->remind($due, false);
        } elseif ($due > 0 && $now->format('H:i') >= '18:00' && Cache::has("marketing.reminded.{$day}") && Cache::add("marketing.nudged.{$day}", true, now()->addDay())) {
            $sent = $this->remind($due, true);
        }

        $this->info("Skipped {$skipped} post(s) for switched-off modules; {$due} still to share today; reminded {$sent} person(s).");

        return self::SUCCESS;
    }

    /** Scheduled posts for today (platform day), with their deliveries. */
    public static function dueToday()
    {
        $start = CarbonImmutable::now(SocialPostPlanner::zone())->startOfDay();

        return SocialPost::query()->where('status', 'scheduled')
            ->whereBetween('scheduled_for', [$start->utc(), $start->endOfDay()->utc()])->with('deliveries');
    }

    private function remind(int $count, bool $evening): int
    {
        $push = array_values(array_intersect(ChannelResolver::resolve([]), [PushChannel::class]));
        $sent = 0;
        User::query()->where('status', '!=', 'suspended')
            ->where(fn ($q) => $q->where('role', 'super_admin')->orWhereHas('roleAssignments'))
            ->chunkById(200, function ($users) use ($count, $evening, $push, &$sent) {
                foreach ($users as $user) {
                    try {
                        if (! $user->hasPermissionAnywhere('marketing.posts.manage')) {
                            continue;
                        }
                        $channels = trim((string) $user->email) !== '' && ! str_ends_with((string) $user->email, '@1callfix.test') ? ['mail'] : [];
                        if ($push && $user->fcm_token) {
                            $channels = array_merge($channels, $push);
                        }
                        if ($channels !== []) {
                            $user->notify(new MarketingPostsReady($count, $evening, $channels));
                            $sent++;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('marketing:posts-due could not remind a user.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return $sent;
    }
}
