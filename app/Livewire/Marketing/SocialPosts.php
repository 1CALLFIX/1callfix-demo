<?php

namespace App\Livewire\Marketing;

use App\Console\Commands\MarketingPostsDue;
use App\Models\City;
use App\Models\Franchise;
use App\Models\SocialPost;
use App\Models\SocialPostDelivery;
use App\Services\ActivityLogger;
use App\Services\AuthorizationService;
use App\Services\Marketing\ModuleGate;
use App\Services\Marketing\PostIdeas;
use App\Services\Marketing\SocialPostPlanner;
use App\Services\SettingsAuditor;
use App\Services\Visitors\PageClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Admin → Marketing & Offers → Social posts (owner 2026-11-21: "schedule posts daily on FB, Insta, Google My Business
 * … if any module is off we don't need to create a digital ad for that section").
 *
 * Today's posts with one Share button per platform (phone: the share sheet with picture + caption; computer: caption
 * copied, picture downloaded, the platform opened) and "Done ✓"; the next weeks; ready-made ideas from live data; a
 * tap-first editor; "Fill my next 7 days" (drafts only). Posts for a switched-off module are skipped with the reason.
 * Behind `marketing.posts.manage`, row-scoped by franchise / city / country; settings are Super Admin only. Audited.
 */
class SocialPosts extends Component
{
    use WithFileUploads;

    public const SCOPE_COLUMNS = ['franchise_id' => 'franchise_id', 'city_id' => 'city_id', 'country_id' => 'country_id'];

    public int $week = 0;

    public bool $showIdeas = false;

    public string $flashMessage = '';

    public string $flashType = 'success';

    // --- editor ---
    public bool $editing = false;

    public ?int $editId = null;

    public string $title = '';

    public string $captionEn = '';

    public string $captionTe = '';

    public string $hashtags = '';

    public string $imagePath = '';

    public $upload = null;

    public string $linkUrl = '';

    public string $module = '';

    public ?int $cityId = null;

    public array $platforms = [];

    public string $day = '';

    public string $time = '';

    // --- "Done ✓" with the post's link ---
    public array $postUrls = [];

    public function mount(): void
    {
        $this->authorizeView();
    }

    private function authorizeView(): void
    {
        abort_unless(auth()->user()?->hasPermissionAnywhere('marketing.posts.manage'), 403, 'You do not have permission to manage social posts.');
    }

    private function posts(): Builder
    {
        return app(AuthorizationService::class)->scopeQuery(SocialPost::query(), auth()->user(), 'marketing.posts.manage', self::SCOPE_COLUMNS);
    }

    private function find(int $id): SocialPost
    {
        $this->authorizeView();

        return $this->posts()->with('deliveries')->findOrFail($id);
    }

    private function flash(string $message, string $type = 'success'): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }

    // ---------------------------------------------------------------- ideas & filling

    public function useIdea(int $index): void
    {
        $this->authorizeView();
        $idea = app(PostIdeas::class)->all($this->defaultCityId())[$index] ?? null;
        if (! $idea) {
            $this->flash('That idea is no longer available. Pick another one.', 'error');

            return;
        }
        $post = app(SocialPostPlanner::class)->fromIdea($idea, auth()->user());
        $this->showIdeas = false;
        $this->edit($post->id);
        $this->day = CarbonImmutable::now(SocialPostPlanner::zone())->addDay()->toDateString();
        $this->flash('Draft made from the idea. Check the words and picture, pick the day, then tap Schedule.');
    }

    public function fillWeek(): void
    {
        $this->authorizeView();
        $made = app(SocialPostPlanner::class)->fillDays(auth()->user(), 7, $this->defaultCityId());
        $this->flash($made > 0
            ? "{$made} draft ".($made === 1 ? 'post' : 'posts').' added for the coming days. Open each one, check it and tap Schedule.'
            : 'Every day of the coming week already has a post, or there are no new ideas right now.', $made > 0 ? 'success' : 'info');
    }

    private function defaultCityId(): ?int
    {
        $user = auth()->user();
        if ($user->role === 'super_admin') {
            return null;
        }
        $assignment = $user->roleAssignments()->whereIn('scope_type', ['city', 'franchise'])->first();
        if (! $assignment) {
            return null;
        }

        return $assignment->scope_type === 'city' ? $assignment->scope_id : Franchise::find($assignment->scope_id)?->city_id;
    }

    // ---------------------------------------------------------------- editor

    public function newPost(): void
    {
        $this->authorizeView();
        $this->resetEditor();
        $this->editing = true;
        $this->platforms = SocialPostPlanner::platforms();
        $this->cityId = $this->defaultCityId();
        $this->day = CarbonImmutable::now(SocialPostPlanner::zone())->addDay()->toDateString();
        $this->time = SocialPostPlanner::postTime();
    }

    public function newPostOn(string $date): void
    {
        $this->newPost();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->day = $date;
        }
    }

    public function edit(int $id): void
    {
        $post = $this->find($id);
        $local = $post->scheduled_for?->setTimezone(SocialPostPlanner::zone());
        $this->resetEditor();
        $this->fill([
            'editing' => true, 'editId' => $post->id, 'title' => $post->title, 'captionEn' => (string) $post->caption_en,
            'captionTe' => (string) $post->caption_te, 'hashtags' => (string) $post->hashtags, 'imagePath' => (string) $post->image_path,
            'linkUrl' => (string) $post->link_url, 'module' => (string) $post->module, 'cityId' => $post->city_id,
            'platforms' => $post->platforms ?: SocialPostPlanner::platforms(),
            'day' => $local?->toDateString() ?? CarbonImmutable::now(SocialPostPlanner::zone())->addDay()->toDateString(),
            'time' => $local?->format('H:i') ?? SocialPostPlanner::postTime(),
        ]);
    }

    public function closeEditor(): void
    {
        $this->resetEditor();
    }

    private function resetEditor(): void
    {
        $this->reset(['editing', 'editId', 'title', 'captionEn', 'captionTe', 'hashtags', 'imagePath', 'upload', 'linkUrl', 'module', 'cityId', 'platforms', 'day', 'time']);
        $this->resetValidation();
    }

    public function pickDay(string $which): void
    {
        $today = CarbonImmutable::now(SocialPostPlanner::zone());
        $this->day = match ($which) {
            'today' => $today->toDateString(),
            'tomorrow' => $today->addDay()->toDateString(),
            default => $today->addDays(2)->toDateString(),
        };
    }

    public function togglePlatform(string $platform): void
    {
        if (! isset(SocialPost::PLATFORMS[$platform])) {
            return;
        }
        $this->platforms = in_array($platform, $this->platforms, true)
            ? array_values(array_diff($this->platforms, [$platform]))
            : array_values(array_merge($this->platforms, [$platform]));
    }

    public function save(bool $schedule): void
    {
        $this->authorizeView();
        $user = auth()->user();
        $this->validate([
            'title' => ['required', 'string', 'max:110'],
            'captionEn' => ['required', 'string', 'max:2000'],
            'captionTe' => ['nullable', 'string', 'max:2000'],
            'hashtags' => ['nullable', 'string', 'max:300'],
            'linkUrl' => ['nullable', 'string', 'max:500', 'regex:#^(/|https://)#'],
            'module' => ['nullable', 'in:'.implode(',', array_keys(ModuleGate::CODES))],
            'cityId' => ['nullable', 'integer', 'exists:cities,id'],
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['in:'.implode(',', array_keys(SocialPost::PLATFORMS))],
            'upload' => ['nullable', 'image', 'max:8192'],
            'day' => [$schedule ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'time' => [$schedule ? 'required' : 'nullable', 'date_format:H:i'],
        ], [
            'linkUrl.regex' => 'The link must start with / (a page on our site) or https://.',
            'platforms.required' => 'Tap at least one place to post.',
            'captionEn.required' => 'Write the words of the post (or pick a ready-made idea).',
        ]);

        $place = $this->placeFor($this->cityId);
        abort_unless(app(AuthorizationService::class)->can($user, 'marketing.posts.manage', array_filter($place)), 403, 'You can only plan posts for your own city.');

        $when = ($this->day && $this->time)
            ? CarbonImmutable::createFromFormat('Y-m-d H:i', $this->day.' '.$this->time, SocialPostPlanner::zone())->utc()
            : null;
        if ($schedule && $when && $when->lt(now()->subMinutes(5)->startOfDay())) {
            $this->addError('day', 'Pick today or a later day.');

            return;
        }
        if ($schedule && ($reason = app(ModuleGate::class)->offReason($this->module ?: null, $this->cityId))) {
            $this->addError('module', $reason.'. Switch it on in Admin → Modules first, or pick another module.');

            return;
        }

        $image = $this->imagePath ?: null;
        if ($this->upload) {
            $image = $this->upload->store('marketing', 'public');
        }

        $data = [
            'title' => $this->title, 'caption_en' => $this->captionEn, 'caption_te' => $this->captionTe ?: null,
            'hashtags' => $this->hashtags ?: null, 'image_path' => $image, 'link_url' => $this->linkUrl ?: null,
            'module' => $this->module ?: null, 'city_id' => $this->cityId, 'platforms' => array_values($this->platforms),
            'scheduled_for' => $when, 'status' => $schedule ? 'scheduled' : 'draft', 'skip_reason' => null,
        ] + $place;

        if ($this->editId) {
            $post = $this->find($this->editId);
            $post->fill($data)->save();
        } else {
            $post = SocialPost::create($data + ['source' => 'manual', 'created_by' => $user->id]);
        }
        ActivityLogger::logModel($user, $post, $schedule ? 'Social post scheduled' : 'Social post saved as draft', ['scheduled_for' => $when?->toIso8601String()]);

        $this->resetEditor();
        $this->flash($schedule
            ? 'Scheduled for '.$when->setTimezone(SocialPostPlanner::zone())->format('D j M, h:i A').'. On the day it shows under Today with a Share button.'
            : 'Saved as a draft.');
    }

    /** @return array{franchise_id: ?int, city_id: ?int, country_id: ?int} */
    private function placeFor(?int $cityId): array
    {
        if (! $cityId) {
            return ['franchise_id' => null, 'city_id' => null, 'country_id' => null];
        }
        $city = City::find($cityId, ['id', 'country_id']);
        $franchises = Franchise::where('city_id', $cityId)->limit(2)->pluck('id');

        return ['franchise_id' => $franchises->count() === 1 ? $franchises->first() : null, 'city_id' => $cityId, 'country_id' => $city?->country_id];
    }

    // ---------------------------------------------------------------- sharing

    public function markDone(int $postId, string $platform): void
    {
        $post = $this->find($postId);
        abort_unless(in_array($platform, $post->platforms ?? [], true), 422);
        $url = trim((string) ($this->postUrls[$postId.'-'.$platform] ?? ''));
        if ($url !== '' && ! preg_match('#^https://#', $url)) {
            $this->flash('The post link must start with https:// (or leave it empty).', 'error');

            return;
        }

        SocialPostDelivery::updateOrCreate(['social_post_id' => $post->id, 'platform' => $platform],
            ['status' => 'posted', 'posted_at' => now(), 'posted_by' => auth()->id(), 'post_url' => $url ?: null, 'error' => null]);
        $post->load('deliveries');
        if (collect($post->platforms)->every(fn ($p) => $post->isPostedOn($p))) {
            $post->forceFill(['status' => 'posted'])->save();
        }
        ActivityLogger::logModel(auth()->user(), $post, 'Social post shared on '.(SocialPost::PLATFORMS[$platform][0] ?? $platform), ['post_url' => $url ?: null]);
        unset($this->postUrls[$postId.'-'.$platform]);
        $this->flash('Marked as posted on '.SocialPost::PLATFORMS[$platform][0].'.');
    }

    public function undoDone(int $postId, string $platform): void
    {
        $post = $this->find($postId);
        SocialPostDelivery::where('social_post_id', $post->id)->where('platform', $platform)->delete();
        if ($post->status === 'posted') {
            $post->forceFill(['status' => 'scheduled'])->save();
        }
        ActivityLogger::logModel(auth()->user(), $post, 'Social post "Done" undone for '.$platform);
    }

    public function skip(int $postId): void
    {
        $post = $this->find($postId);
        $post->forceFill(['status' => 'skipped', 'skip_reason' => 'Skipped by '.auth()->user()->name])->save();
        ActivityLogger::logModel(auth()->user(), $post, 'Social post skipped');
        $this->flash('Post skipped. You can bring it back with Restore.');
    }

    public function restore(int $postId): void
    {
        $post = $this->find($postId);
        if ($reason = app(ModuleGate::class)->offReason($post->module, $post->city_id)) {
            $this->flash($reason.'. Switch it on in Admin → Modules first.', 'error');

            return;
        }
        $post->forceFill(['status' => 'draft', 'skip_reason' => null])->save();
        ActivityLogger::logModel(auth()->user(), $post, 'Social post restored as draft');
        $this->flash('Restored as a draft. Check the day and tap Schedule.');
    }

    public function delete(int $postId): void
    {
        $post = $this->find($postId);
        if ($post->status === 'posted' || $post->deliveries->contains('status', 'posted')) {
            $this->flash('A post that went out is kept for the record. Skip it instead.', 'error');

            return;
        }
        ActivityLogger::logModel(auth()->user(), $post, 'Social post deleted', ['title' => $post->title]);
        $post->delete();
        $this->flash('Post deleted.');
    }

    // ---------------------------------------------------------------- settings (Super Admin)

    public function toggleDefaultPlatform(string $platform): void
    {
        $this->authorizeSettings();
        if (! isset(SocialPost::PLATFORMS[$platform])) {
            return;
        }
        $now = SocialPostPlanner::platforms();
        $next = in_array($platform, $now, true) ? array_values(array_diff($now, [$platform])) : array_merge($now, [$platform]);
        if ($next === []) {
            $this->flash('Keep at least one place to post.', 'error');

            return;
        }
        SettingsAuditor::put(auth()->user(), SocialPostPlanner::PLATFORMS_SETTING, implode(',', $next));
    }

    public function setTime(string $which, string $value): void
    {
        $this->authorizeSettings();
        abort_unless(in_array($which, ['reminder', 'post'], true) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value), 422);
        SettingsAuditor::put(auth()->user(), $which === 'reminder' ? SocialPostPlanner::REMINDER_SETTING : SocialPostPlanner::POST_TIME_SETTING, $value);
        $this->flash($which === 'reminder' ? "The team is reminded at {$value} each day." : "New posts are planned for {$value}.");
    }

    private function authorizeSettings(): void
    {
        abort_unless(auth()->user()?->role === 'super_admin', 403, 'Only Super Admin can change these settings.');
    }

    // ---------------------------------------------------------------- render

    public function render()
    {
        $this->authorizeView();
        $zone = SocialPostPlanner::zone();
        $today = CarbonImmutable::now($zone)->startOfDay();
        $from = $today->addWeeks($this->week);
        $to = $from->addDays(13)->endOfDay();

        $todayPosts = $this->posts()->whereIn('id', MarketingPostsDue::dueToday()->pluck('id'))->with('deliveries')->orderBy('scheduled_for')->get();
        $missed = $this->posts()->where('status', 'scheduled')->whereBetween('scheduled_for', [$today->subDays(3)->utc(), $today->utc()])
            ->with('deliveries')->orderBy('scheduled_for')->get();
        $upcoming = $this->posts()->whereBetween('scheduled_for', [$from->utc(), $to->utc()])->with('deliveries')->orderBy('scheduled_for')->get()
            ->groupBy(fn ($p) => $p->scheduled_for->setTimezone($zone)->toDateString());
        $unscheduled = $this->posts()->whereNull('scheduled_for')->where('status', 'draft')->latest()->limit(20)->get();

        $cities = City::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        if (auth()->user()->role !== 'super_admin') {
            $cities = $cities->filter(fn ($c) => app(AuthorizationService::class)->can(auth()->user(), 'marketing.posts.manage', array_filter($this->placeFor($c->id))))->values();
        }

        return view('livewire.marketing.social-posts', [
            'todayPosts' => $todayPosts,
            'missed' => $missed,
            'days' => collect(range(0, 13))->map(fn ($i) => $from->addDays($i)),
            'upcoming' => $upcoming,
            'unscheduled' => $unscheduled,
            'ideas' => $this->showIdeas ? app(PostIdeas::class)->all($this->defaultCityId()) : [],
            'platformMeta' => SocialPost::PLATFORMS,
            'moduleLabels' => array_intersect_key(PageClassifier::MODULE_LABELS, ModuleGate::CODES),
            'cities' => $cities,
            'isSuperAdmin' => auth()->user()->role === 'super_admin',
            'defaultPlatforms' => SocialPostPlanner::platforms(),
            'reminderTime' => SocialPostPlanner::reminderTime(),
            'postTime' => SocialPostPlanner::postTime(),
            'zone' => $zone,
            'today' => $today,
        ])->layout('layouts.admin', ['title' => 'Social posts']);
    }
}
