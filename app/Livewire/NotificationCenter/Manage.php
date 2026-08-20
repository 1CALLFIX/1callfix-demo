<?php

namespace App\Livewire\NotificationCenter;

use App\Contracts\PushAdapter;
use App\Contracts\SmsAdapter;
use App\Models\City;
use App\Models\Coupon;
use App\Models\Country;
use App\Models\Franchise;
use App\Models\NotificationCampaign;
use App\Models\NotificationLog;
use App\Models\NotificationMeeting;
use App\Models\NotificationTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Notifications\Adapters\LogPushAdapter;
use App\Notifications\Adapters\LogSmsAdapter;
use App\Services\AudienceResolver;
use App\Services\AuthorizationService;
use App\Services\CampaignService;
use App\Services\MeetingService;
use App\Support\Modules;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The admin-facing half of the Notification Center — composes a Campaign
 * or Meeting, which then flows through CampaignService/MeetingService into
 * the exact same Notification/Channel/Adapter/notification_logs pipeline
 * the transactional notifications already use. See CampaignNotification's
 * docblock for the "one delivery architecture, two sources" shape.
 */
class Manage extends Component
{
    use WithPagination;

    public string $section = 'compose'; // compose|campaigns|meetings|templates|logs|status

    /**
     * notification.view was seeded (2026_08_11_024000) but never checked on
     * this screen (only the compose/schedule/campaign/meeting actions check
     * their own specific permissions) -- see Commissions\Index's identical
     * fix for the full reasoning.
     *
     * Row-level scoping (was deferred here, now closed): campaigns/meetings
     * each carry their OWN single (scope_type, scope_id) pair, the exact
     * Plan-shaped pattern NotificationCampaign::authorizationScopeHint()/
     * NotificationMeeting::authorizationScopeHint() now expose -- filtered
     * the same "small catalog, filter-then-whereIn" way as Plans\Manage.
     */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('notification.view'), 403, 'You do not have permission to view the notification center.');
    }

    private function visibleCampaignIds(): array
    {
        $candidates = NotificationCampaign::query()->select('id', 'scope_type', 'scope_id')->get();

        return app(AuthorizationService::class)->visibleAmong($candidates, auth()->user(), 'notification.view')->pluck('id')->all();
    }

    private function visibleMeetingIds(): array
    {
        $candidates = NotificationMeeting::query()->select('id', 'scope_type', 'scope_id')->get();

        return app(AuthorizationService::class)->visibleAmong($candidates, auth()->user(), 'notification.view')->pluck('id')->all();
    }

    // --- Compose ---
    public string $category = 'business';
    public string $type = 'promotion';
    public string $title = '';
    public string $message = '';
    public string $imageUrl = '';
    public string $actionUrl = '';
    public string $module = '';
    public string $recipientType = 'everyone';
    public string $specificUserSearch = '';
    public ?int $specificUserId = null;
    public string $scopeType = 'global';
    public ?int $scopeCountryId = null;
    public ?int $scopeCityId = null;
    public ?int $scopeFranchiseId = null;
    public ?int $scopeZoneId = null;
    public bool $filterActiveOnly = false;
    public bool $filterPrimeOnly = false;
    public bool $filterOnlineOnly = false;
    public bool $filterSubscriptionActive = false;
    public bool $chMail = true;
    public bool $chSms = false;
    public bool $chPush = false;
    public bool $chInApp = false;
    public string $priority = 'normal';
    public ?int $couponId = null;
    public ?int $templateId = null;
    public string $scheduledAt = '';
    public string $expiresAt = '';

    // --- Meetings: create ---
    public string $meetingTitle = '';
    public string $meetingDescription = '';
    public string $meetingStartsAt = '';
    public string $meetingDuration = '60';
    public string $meetingLocation = '';
    public string $meetingLink = '';
    public string $meetingRecipientType = 'providers';
    public string $meetingScopeType = 'global';
    public ?int $meetingScopeFranchiseId = null;
    public string $meetingReminders = '1440,120'; // comma list of minutes-before, admin-editable

    public string $flashMessage = '';
    public string $flashType = 'success';

    public function updatedScopeType(): void { $this->reset(['scopeCountryId', 'scopeCityId', 'scopeFranchiseId', 'scopeZoneId']); }
    public function updatedScopeCountryId(): void { $this->scopeCityId = null; }
    public function updatedScopeFranchiseId(): void { $this->scopeZoneId = null; }

    public function getMatchingUsersProperty()
    {
        if (mb_strlen($this->specificUserSearch) < 2) {
            return collect();
        }

        return User::where('name', 'like', "%{$this->specificUserSearch}%")
            ->orWhere('phone', 'like', "%{$this->specificUserSearch}%")
            ->limit(8)->get();
    }

    public function selectSpecificUser(int $id): void
    {
        $this->specificUserId = $id;
        $this->specificUserSearch = User::find($id)?->name ?? '';
    }

    public function applyTemplate(): void
    {
        if (! $this->templateId) {
            return;
        }

        $t = NotificationTemplate::find($this->templateId);
        if ($t) {
            $this->title = $t->title_template;
            $this->message = $t->body_template;
        }
    }

    /** [scope_type, scope_id] from whichever picker is active. */
    private function resolvedScope(): array
    {
        return match ($this->scopeType) {
            'country' => ['country', $this->scopeCountryId],
            'city' => ['city', $this->scopeCityId],
            'franchise' => ['franchise', $this->scopeFranchiseId],
            'zone' => ['zone', $this->scopeZoneId],
            default => ['global', null],
        };
    }

    private function audienceSpecFromForm(): array
    {
        [$scopeType, $scopeId] = $this->resolvedScope();

        return [
            'recipient_type' => $this->recipientType,
            'specific_user_id' => $this->specificUserId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'module' => $this->module ?: null,
            'filters' => array_filter([
                'active_only' => $this->filterActiveOnly,
                'prime_only' => $this->filterPrimeOnly,
                'online_only' => $this->filterOnlineOnly,
                'subscription_active' => $this->filterSubscriptionActive,
            ]),
        ];
    }

    public function getPreviewCountProperty(): int
    {
        if ($this->recipientType === 'specific_user' && ! $this->specificUserId) {
            return 0;
        }

        return app(AudienceResolver::class)->resolve($this->audienceSpecFromForm())->count();
    }

    private function channelsString(): string
    {
        return implode(',', array_filter([
            $this->chMail ? 'mail' : null,
            $this->chSms ? 'sms' : null,
            $this->chPush ? 'push' : null,
            $this->chInApp ? 'in_app' : null,
        ]));
    }

    /**
     * notification.send AND (notification.broadcast or .direct for a
     * specific user), both checked against the CHOSEN scope — not a global/
     * empty scope hint, since a scoped role's grants (e.g. a Country Admin's
     * country-scoped assignment) only cover requests carrying that same
     * scope. A Country Admin can send within their own country but not
     * target a different one, and can't send at all without a scope-
     * covering grant on notification.send either.
     */
    private function authorizeSend(): bool
    {
        $scopeHint = $this->scopeHintForAuthorization();

        if ($this->recipientType === 'specific_user') {
            return auth()->user()->hasPermission('notification.send', $scopeHint)
                && auth()->user()->hasPermission('notification.direct', $scopeHint);
        }

        return auth()->user()->hasPermission('notification.send', $scopeHint)
            && auth()->user()->hasPermission('notification.broadcast', $scopeHint);
    }

    private function scopeHintForAuthorization(): array
    {
        [$scopeType, $scopeId] = $this->resolvedScope();

        return $scopeType === 'global' ? [] : ["{$scopeType}_id" => $scopeId];
    }

    private function validated(): array
    {
        return $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'recipientType' => ['required', 'in:customers,providers,staff,everyone,specific_user'],
        ], [], ['title' => 'title', 'message' => 'message']);
    }

    public function sendNow(): void
    {
        if (! $this->authorizeSend()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to send to this audience/scope.';
            return;
        }

        $this->validated();
        [$scopeType, $scopeId] = $this->resolvedScope();

        $campaign = NotificationCampaign::create([
            'category' => $this->category, 'type' => $this->type, 'title' => $this->title, 'message' => $this->message,
            'image_url' => $this->imageUrl ?: null, 'action_url' => $this->actionUrl ?: null, 'module' => $this->module ?: null,
            'recipient_type' => $this->recipientType, 'specific_user_id' => $this->specificUserId,
            'scope_type' => $scopeType, 'scope_id' => $scopeId,
            'filters' => $this->audienceSpecFromForm()['filters'],
            'channels' => $this->channelsString() ?: 'mail', 'priority' => $this->priority,
            'coupon_id' => $this->couponId, 'template_id' => $this->templateId,
            'status' => 'draft', 'expires_at' => $this->expiresAt ?: null, 'created_by' => auth()->id(),
        ]);

        try {
            app(CampaignService::class)->send($campaign);
            $this->flashType = 'success';
            $this->flashMessage = "Sent to {$campaign->fresh()->recipient_count} recipient(s).";
            $this->resetComposeForm();
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function scheduleSend(): void
    {
        $scopeHint = $this->scopeHintForAuthorization();
        if (! auth()->user()->hasPermission('notification.schedule', $scopeHint) || ! $this->authorizeSend()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to schedule for this audience/scope.';
            return;
        }

        $this->validated();
        $this->validate(['scheduledAt' => ['required', 'date', 'after:now']], [], ['scheduledAt' => 'scheduled time']);

        [$scopeType, $scopeId] = $this->resolvedScope();

        $campaign = NotificationCampaign::create([
            'category' => $this->category, 'type' => $this->type, 'title' => $this->title, 'message' => $this->message,
            'image_url' => $this->imageUrl ?: null, 'action_url' => $this->actionUrl ?: null, 'module' => $this->module ?: null,
            'recipient_type' => $this->recipientType, 'specific_user_id' => $this->specificUserId,
            'scope_type' => $scopeType, 'scope_id' => $scopeId,
            'filters' => $this->audienceSpecFromForm()['filters'],
            'channels' => $this->channelsString() ?: 'mail', 'priority' => $this->priority,
            'coupon_id' => $this->couponId, 'template_id' => $this->templateId,
            'status' => 'draft', 'expires_at' => $this->expiresAt ?: null, 'created_by' => auth()->id(),
        ]);

        app(CampaignService::class)->schedule($campaign, \Carbon\Carbon::parse($this->scheduledAt));
        $this->flashType = 'success';
        $this->flashMessage = 'Campaign scheduled for '.$this->scheduledAt.'.';
        $this->resetComposeForm();
    }

    private function resetComposeForm(): void
    {
        $this->reset([
            'title', 'message', 'imageUrl', 'actionUrl', 'module', 'specificUserSearch', 'specificUserId',
            'couponId', 'templateId', 'scheduledAt', 'expiresAt', 'filterActiveOnly', 'filterPrimeOnly',
            'filterOnlineOnly', 'filterSubscriptionActive',
        ]);
    }

    public function cancelCampaign(int $campaignId): void
    {
        if (! auth()->user()->hasPermission('notification.manage_campaigns')) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage campaigns.';
            return;
        }

        $campaign = NotificationCampaign::findOrFail($campaignId);
        if (in_array($campaign->status, ['scheduled', 'draft'], true)) {
            $campaign->update(['status' => 'cancelled']);
            $this->flashType = 'success';
            $this->flashMessage = 'Campaign cancelled.';
        }
    }

    /**
     * Real, working retry -- see CampaignService::resendToFailedRecipients()'s
     * own docblock. notification.manage_campaigns (the same permission
     * cancelCampaign() already checks) covers this too, not a new
     * permission for what's conceptually the same "manage an existing
     * campaign" capability.
     */
    public function resendFailed(int $campaignId): void
    {
        if (! auth()->user()->hasPermission('notification.manage_campaigns')) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage campaigns.';
            return;
        }

        $campaign = NotificationCampaign::findOrFail($campaignId);

        try {
            $count = app(CampaignService::class)->resendToFailedRecipients($campaign);
            $this->flashType = 'success';
            $this->flashMessage = $count > 0 ? "Resent to {$count} previously-failed recipient(s)." : 'No currently-failed recipients to resend to.';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    // ============================== Templates ==============================
    // notification_templates/notification.manage_templates both already
    // existed (seeded 2026_08_11_024000) but had ZERO admin UI anywhere --
    // the compose screen could only PICK a template via templateId, never
    // create/edit/delete one. Real CRUD, no invented content.

    public ?int $editingTemplateId = null;
    public string $templateKey = '';
    public string $templateName = '';
    public string $templateTitle = '';
    public string $templateBody = '';
    public string $templateDescription = '';

    private function canManageTemplates(): bool
    {
        return auth()->user()->hasPermissionAnywhere('notification.manage_templates');
    }

    public function startEditingTemplate(?int $templateId = null): void
    {
        $this->editingTemplateId = $templateId;
        if ($templateId) {
            $t = NotificationTemplate::findOrFail($templateId);
            $this->templateKey = $t->key;
            $this->templateName = $t->name;
            $this->templateTitle = $t->title_template;
            $this->templateBody = $t->body_template;
            $this->templateDescription = $t->description ?? '';
        } else {
            $this->reset(['templateKey', 'templateName', 'templateTitle', 'templateBody', 'templateDescription']);
        }
    }

    public function saveTemplate(): void
    {
        if (! $this->canManageTemplates()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage notification templates.';
            return;
        }

        $this->validate([
            'templateKey' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:notification_templates,key,'.($this->editingTemplateId ?: 'NULL').',id'],
            'templateName' => ['required', 'string', 'max:150'],
            'templateTitle' => ['required', 'string', 'max:255'],
            'templateBody' => ['required', 'string'],
        ], [], ['templateKey' => 'key', 'templateName' => 'name', 'templateTitle' => 'title template', 'templateBody' => 'body template']);

        NotificationTemplate::updateOrCreate(
            ['id' => $this->editingTemplateId],
            [
                'key' => $this->templateKey, 'name' => $this->templateName,
                'title_template' => $this->templateTitle, 'body_template' => $this->templateBody,
                'description' => $this->templateDescription ?: null,
            ]
        );

        $this->flashType = 'success';
        $this->flashMessage = $this->editingTemplateId ? 'Template updated.' : 'Template created.';
        $this->startEditingTemplate(null);
    }

    public function deleteTemplate(int $templateId): void
    {
        if (! $this->canManageTemplates()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage notification templates.';
            return;
        }

        NotificationTemplate::where('id', $templateId)->delete();
        $this->flashType = 'success';
        $this->flashMessage = 'Template deleted.';
    }

    // ============================== Delivery logs ==============================
    // notification_logs/notification.view_logs both already existed
    // (AppServiceProvider's NotificationSent/NotificationFailed listeners
    // write every attempt) but had ZERO admin browsing UI -- Operations\Health
    // only ever showed the last 20 FAILURES with no filtering. This is the
    // real, filterable log browser.

    public string $logChannelFilter = '';
    public string $logStatusFilter = '';
    public string $logSearch = '';

    public function updatingLogChannelFilter() { $this->resetPage('logsPage'); }
    public function updatingLogStatusFilter() { $this->resetPage('logsPage'); }
    public function updatingLogSearch() { $this->resetPage('logsPage'); }

    // ============================== Meetings ==============================

    public function createMeeting(): void
    {
        if (! auth()->user()->hasPermission('notification.manage_meetings')) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage meetings.';
            return;
        }

        $this->validate([
            'meetingTitle' => ['required', 'string', 'max:255'],
            'meetingStartsAt' => ['required', 'date', 'after:now'],
            'meetingRecipientType' => ['required', 'in:customers,providers,staff,everyone,specific_user'],
        ], [], ['meetingTitle' => 'title', 'meetingStartsAt' => 'start time']);

        $scopeId = $this->meetingScopeType === 'franchise' ? $this->meetingScopeFranchiseId : null;
        $offsets = array_filter(array_map('intval', explode(',', $this->meetingReminders)));

        $meeting = NotificationMeeting::create([
            'title' => $this->meetingTitle, 'description' => $this->meetingDescription ?: null,
            'starts_at' => $this->meetingStartsAt, 'duration_minutes' => (int) $this->meetingDuration,
            'location' => $this->meetingLocation ?: null, 'meeting_link' => $this->meetingLink ?: null,
            'recipient_type' => $this->meetingRecipientType,
            'scope_type' => $this->meetingScopeType, 'scope_id' => $scopeId,
            'organizer_user_id' => auth()->id(), 'reminder_offsets_minutes' => $offsets, 'status' => 'scheduled',
        ]);

        app(MeetingService::class)->scheduleMeeting($meeting);

        $this->flashType = 'success';
        $this->flashMessage = 'Meeting scheduled and announcement sent.';
        $this->reset(['meetingTitle', 'meetingDescription', 'meetingStartsAt', 'meetingLocation', 'meetingLink']);
    }

    /** Which adapter is actually bound right now -- read-only, no credentials ever surfaced (LogSmsAdapter/LogPushAdapter hold none; a real adapter's own config stays server-side). */
    private function providerStatus(): array
    {
        $smsAdapter = get_class(app(SmsAdapter::class));
        $pushAdapter = get_class(app(PushAdapter::class));

        return [
            'sms_adapter' => class_basename($smsAdapter),
            'sms_is_log_fallback' => $smsAdapter === LogSmsAdapter::class,
            'push_adapter' => class_basename($pushAdapter),
            'push_is_log_fallback' => $pushAdapter === LogPushAdapter::class,
        ];
    }

    /** Sent/failed counts per channel over the last 30 days -- real numbers from notification_logs, not a fabricated dashboard. */
    private function deliveryStats(): array
    {
        return NotificationLog::where('created_at', '>=', now()->subDays(30))
            ->selectRaw('channel, status, count(*) as total')
            ->groupBy('channel', 'status')
            ->get()
            ->groupBy('channel')
            ->map(fn ($rows) => $rows->pluck('total', 'status'))
            ->all();
    }

    /**
     * Admin Command Center completion session, CMS/Communication Center
     * phase (2026-08-20) -- notification.view_logs was seeded (migration
     * 2026_08_11_024000) with the identical "assignable to other roles...
     * once a real business need shows up" language already found to be a
     * real gap twice this session (operations.view/item 45, banners.manage/
     * item 47), but this query applied zero row-level scope. NotificationLog
     * .notifiable is polymorphic (Illuminate's own notification-sent
     * listener in AppServiceProvider writes get_class($event->notifiable)
     * for every ->notify() call in the app) -- confirmed by reading every
     * ->notify() call site in app/ that the ONE real notifiable type ever
     * used is User (AudienceResolver, every Action's own $x->customer/
     * $x->user call, CampaignService's recipient loop -- all User::query()
     * or a User instance, never FieldWorker or anything else directly), so
     * whereHasMorph() against that single known type is the honest,
     * evidence-based scope, not a guess at a schema whereHasMorph can't
     * prove exists.
     */
    public function render()
    {
        $user = auth()->user();

        $logColumns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

        $logs = $user->hasPermissionAnywhere('notification.view_logs')
            ? NotificationLog::with('notifiable')
                ->whereHasMorph('notifiable', [User::class], fn ($q) => app(AuthorizationService::class)
                    ->scopeQuery($q, $user, 'notification.view_logs', $logColumns))
                ->when($this->logChannelFilter !== '', fn ($q) => $q->where('channel', $this->logChannelFilter))
                ->when($this->logStatusFilter !== '', fn ($q) => $q->where('status', $this->logStatusFilter))
                ->when($this->logSearch !== '', fn ($q) => $q->where(function ($w) {
                    $w->where('event', 'like', "%{$this->logSearch}%")
                        ->orWhere('notification_type', 'like', "%{$this->logSearch}%")
                        ->orWhere('error', 'like', "%{$this->logSearch}%");
                }))
                ->latest('id')->paginate(25, ['*'], 'logsPage')
            : null;

        return view('livewire.notification-center.manage', [
            'campaigns' => NotificationCampaign::whereIn('id', $this->visibleCampaignIds())->latest()->paginate(15, ['*'], 'campaignsPage'),
            'meetings' => NotificationMeeting::whereIn('id', $this->visibleMeetingIds())->latest()->paginate(15, ['*'], 'meetingsPage'),
            'templates' => NotificationTemplate::orderBy('name')->get(),
            'logs' => $logs,
            'canViewLogs' => $user->hasPermissionAnywhere('notification.view_logs'),
            'canManageTemplates' => $this->canManageTemplates(),
            'providerStatus' => $this->providerStatus(),
            'deliveryStats' => $this->deliveryStats(),
            'coupons' => Coupon::where('is_active', true)->orderBy('code')->get(),
            'countries' => Country::where('is_active', true)->orderBy('name')->get(),
            'cities' => $this->scopeCountryId ? City::where('country_id', $this->scopeCountryId)->orderBy('name')->get() : collect(),
            'franchises' => Franchise::where('status', 'active')->orderBy('name')->get(),
            'zones' => $this->scopeFranchiseId ? Zone::where('franchise_id', $this->scopeFranchiseId)->orderBy('name')->get() : collect(),
            'moduleOptions' => Modules::ALL,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('layouts.admin', ['title' => 'Notification Center']);
    }
}
