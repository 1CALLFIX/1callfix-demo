<?php

namespace App\Livewire\NotificationCenter;

use App\Models\City;
use App\Models\Coupon;
use App\Models\Country;
use App\Models\Franchise;
use App\Models\NotificationCampaign;
use App\Models\NotificationMeeting;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Models\Zone;
use App\Services\AudienceResolver;
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

    public string $section = 'compose'; // compose|campaigns|meetings

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

    /** notification.broadcast (or .direct for a specific user) checked against the CHOSEN scope — a Country Admin can't target a different country. */
    private function authorizeSend(): bool
    {
        if ($this->recipientType === 'specific_user') {
            return auth()->user()->hasPermission('notification.direct');
        }

        [$scopeType, $scopeId] = $this->resolvedScope();
        $scopeHint = $scopeType === 'global' ? [] : ["{$scopeType}_id" => $scopeId];

        return auth()->user()->hasPermission('notification.broadcast', $scopeHint);
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
        if (! auth()->user()->hasPermission('notification.send') || ! $this->authorizeSend()) {
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
        if (! auth()->user()->hasPermission('notification.schedule') || ! $this->authorizeSend()) {
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

    public function render()
    {
        return view('livewire.notification-center.manage', [
            'campaigns' => NotificationCampaign::latest()->paginate(15, ['*'], 'campaignsPage'),
            'meetings' => NotificationMeeting::latest()->paginate(15, ['*'], 'meetingsPage'),
            'templates' => NotificationTemplate::orderBy('name')->get(),
            'coupons' => Coupon::where('is_active', true)->orderBy('code')->get(),
            'countries' => Country::where('is_active', true)->orderBy('name')->get(),
            'cities' => $this->scopeCountryId ? City::where('country_id', $this->scopeCountryId)->orderBy('name')->get() : collect(),
            'franchises' => Franchise::where('status', 'active')->orderBy('name')->get(),
            'zones' => $this->scopeFranchiseId ? Zone::where('franchise_id', $this->scopeFranchiseId)->orderBy('name')->get() : collect(),
            'moduleOptions' => Modules::ALL,
        ])->layout('layouts.admin', ['title' => 'Notification Center']);
    }
}
