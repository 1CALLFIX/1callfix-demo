<?php

namespace App\Livewire\Kyc;

use App\Models\Franchise;
use App\Models\KycSupportRequest;
use App\Models\Provider;
use App\Services\AuthorizationService;
use App\Services\Kyc\KycSupportRequestService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "KYC / Withdrawal Restriction Support Request" screen (mission Phase 4).
 * One screen, two roles: a Franchise-scoped actor holding kyc.support_requests.create
 * raises requests for THEIR OWN franchise's providers; a Central Admin
 * holding the separate kyc.support_requests.decide permission approves/
 * rejects/requests more info — never the same action, never the same
 * button. A franchise actor never sees an "enable withdrawal" control
 * anywhere on this screen, by construction (decide() is gated out entirely
 * for them).
 */
class SupportRequests extends Component
{
    use WithPagination;

    public ?int $providerId = null;
    public string $providerSearch = '';
    public string $reason = '';
    public string $urgency = 'normal';
    public string $assistanceProvided = '';

    public ?int $decidingRequestId = null;
    public string $decisionNote = '';
    public string $exceptionDays = '';

    public string $flashMessage = '';
    public string $flashType = 'success';

    public function mount(): void
    {
        abort_unless(
            auth()->user()->hasPermissionAnywhere('kyc.support_requests.create') || auth()->user()->hasPermissionAnywhere('kyc.support_requests.decide'),
            403,
            'You do not have permission to view KYC support requests.'
        );
    }

    public function getMatchingProvidersProperty()
    {
        if (mb_strlen($this->providerSearch) < 2) {
            return collect();
        }

        return Provider::with('user')->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$this->providerSearch.'%'))->limit(8)->get();
    }

    public function selectProvider(int $providerId): void
    {
        $this->providerId = $providerId;
        $this->providerSearch = Provider::find($providerId)?->user?->name ?? '';
    }

    public function create(KycSupportRequestService $service): void
    {
        $provider = Provider::find($this->providerId);
        if (! $provider) {
            $this->addError('providerId', 'Select a provider first.');
            return;
        }

        $franchise = Franchise::findOrFail($provider->franchise_id);

        if (! auth()->user()->hasPermission('kyc.support_requests.create', array_filter(['franchise_id' => $franchise->id]))) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to raise a support request for this franchise.';
            return;
        }

        $this->validate(['reason' => ['required', 'string', 'min:5']]);

        $service->create($provider, $franchise, auth()->user(), $this->reason, [], $this->assistanceProvided ?: null, $this->urgency);

        $this->reset(['providerId', 'providerSearch', 'reason', 'assistanceProvided']);
        $this->flashType = 'success';
        $this->flashMessage = 'Support request raised.';
    }

    public function startDeciding(int $requestId): void
    {
        $this->decidingRequestId = $requestId;
        $this->decisionNote = '';
        $this->exceptionDays = '';
    }

    public function decide(int $requestId, string $outcome, KycSupportRequestService $service): void
    {
        if (! auth()->user()->hasPermissionAnywhere('kyc.support_requests.decide')) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to decide KYC support requests — only Central Admin can.';
            return;
        }

        $request = KycSupportRequest::findOrFail($requestId);

        try {
            $service->decide($request, auth()->user(), $outcome, $this->decisionNote ?: null, $this->exceptionDays !== '' ? (int) $this->exceptionDays : null);
            $this->flashType = 'success';
            $this->flashMessage = 'Support request '.str_replace('_', ' ', $outcome).'.';
            $this->decidingRequestId = null;
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    /**
     * Admin Command Center completion session, Admin UX/Performance phase
     * (2026-08-20, same shape as item 44) -- this used to fetch the FULL
     * table (with eager loads) via ->get(), TWICE in some branches (once
     * for the redundant `$user->role === 'super_admin'` special case,
     * again as visibleAmong()'s own argument -- visibleAmong() already
     * bypasses scoping for super_admin internally, so that branch was
     * always dead weight, not a distinct behavior), before filtering
     * in-memory, with no pagination ever wired. KycSupportRequest carries
     * a direct franchise_id (not the scope_type/scope_id flat pair
     * FlashSale/Payout use), so this is a direct scopeQuery() call rather
     * than the id-projection pattern those use -- same real database
     * filtering + bounded pagination outcome either way.
     */
    public function render()
    {
        $user = auth()->user();
        $permission = $user->hasPermissionAnywhere('kyc.support_requests.decide') ? 'kyc.support_requests.decide' : 'kyc.support_requests.create';

        $requests = app(AuthorizationService::class)
            ->scopeQuery(KycSupportRequest::query(), $user, $permission, ['franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'])
            ->with(['provider.user', 'franchise.country', 'raisedBy'])
            ->latest()
            ->paginate(15);

        return view('livewire.kyc.support-requests', [
            'requests' => $requests,
            'canDecide' => $user->hasPermissionAnywhere('kyc.support_requests.decide'),
        ])->layout('layouts.admin', ['title' => 'KYC Support Requests']);
    }
}
