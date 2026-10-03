<?php

namespace App\Livewire\RefundControls;

use App\Models\Setting;
use App\Services\Payments\AmountMismatchService;
use App\Services\Payments\MismatchRefundService;
use App\Services\SettingsAuditor;
use App\Support\SuperAdminGate;
use Livewire\Component;

/**
 * Every refund.mismatch.* control and both customer notice texts in one
 * place (MANUAL MONEY ACTIONS model). Super Admin only — the role itself,
 * re-checked on every save so a direct Livewire call from anyone else is a
 * 403 — and every changed value is written through SettingsAuditor (audit
 * logged). Blank = not configured: a blank limit means that level cannot
 * approve, a blank threshold / escalation turns that feature off.
 */
class Manage extends Component
{
    public string $franchiseLimit = '';

    public string $hqLimit = '';

    public string $dualApprovalAbove = '';

    public string $escalateAfterHours = '';

    public string $noticeUnderReview = '';

    public string $noticeRefunded = '';

    public string $flashType = 'success';

    public string $flashMessage = '';

    public function mount(): void
    {
        SuperAdminGate::authorize(auth()->user());

        $this->franchiseLimit = (string) Setting::get(MismatchRefundService::FRANCHISE_LIMIT_KEY, '');
        $this->hqLimit = (string) Setting::get(MismatchRefundService::HQ_LIMIT_KEY, '');
        $this->dualApprovalAbove = (string) Setting::get(MismatchRefundService::DUAL_APPROVAL_KEY, '');
        $this->escalateAfterHours = (string) Setting::get(MismatchRefundService::ESCALATE_HOURS_KEY, '');

        $notices = app(AmountMismatchService::class);
        $this->noticeUnderReview = $notices->customerMessage();
        $this->noticeRefunded = trim((string) Setting::get(AmountMismatchService::COPY_REFUNDED_KEY, '')) ?: AmountMismatchService::DEFAULT_REFUNDED;
    }

    public function save(): void
    {
        SuperAdminGate::authorize(auth()->user());

        $this->validate([
            'franchiseLimit' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'hqLimit' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'dualApprovalAbove' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'escalateAfterHours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'noticeUnderReview' => ['required', 'string', 'min:10', 'max:300'],
            'noticeRefunded' => ['required', 'string', 'min:10', 'max:300'],
        ], [], [
            'franchiseLimit' => 'franchise limit',
            'hqLimit' => 'HQ limit',
            'dualApprovalAbove' => 'second-approval threshold',
            'escalateAfterHours' => 'escalation hours',
            'noticeUnderReview' => 'under-review message',
            'noticeRefunded' => 'refund message',
        ]);

        if ($this->franchiseLimit !== '' && $this->hqLimit !== '' && (float) $this->franchiseLimit > (float) $this->hqLimit) {
            $this->addError('franchiseLimit', 'The franchise limit cannot be higher than the HQ limit.');

            return;
        }

        if (! str_contains($this->noticeRefunded, '[amount]')) {
            $this->addError('noticeRefunded', 'The refund message must contain [amount] so the customer sees the refunded amount.');

            return;
        }

        $admin = auth()->user();
        $changed = false;

        foreach ([
            MismatchRefundService::FRANCHISE_LIMIT_KEY => $this->franchiseLimit,
            MismatchRefundService::HQ_LIMIT_KEY => $this->hqLimit,
            MismatchRefundService::DUAL_APPROVAL_KEY => $this->dualApprovalAbove,
            MismatchRefundService::ESCALATE_HOURS_KEY => $this->escalateAfterHours,
            AmountMismatchService::COPY_UNDER_REVIEW_KEY => trim($this->noticeUnderReview),
            AmountMismatchService::COPY_REFUNDED_KEY => trim($this->noticeRefunded),
        ] as $key => $value) {
            $changed = SettingsAuditor::put($admin, $key, $value) || $changed;
        }

        $this->flashType = 'success';
        $this->flashMessage = $changed ? 'Refund controls saved.' : 'Nothing changed.';
    }

    public function render()
    {
        return view('livewire.refund-controls.manage')
            ->layout('layouts.admin', ['title' => 'Refund Controls']);
    }
}
