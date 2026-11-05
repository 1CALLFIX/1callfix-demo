<?php

namespace App\Livewire\AccountDeletions;

use App\Models\User;
use App\Services\Account\AccountDeletionService;
use App\Support\SuperAdminGate;
use Livewire\Component;

/**
 * Admin → Users & Partners → Account deletions (Google Play, owner 2026-11-05). Super Admin only.
 * Waiting: partners who asked — settle their dues and payouts first, then Close. Closed: sign-in stopped; details are
 * erased automatically 30 days after closing (accounts:erase-closed), Reopen undoes it before then.
 */
class Index extends Component
{
    public string $flashMessage = '';

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    private function authorizeAdmin(): void
    {
        abort_unless(SuperAdminGate::allows(auth()->user()), 403, 'Only a Super Admin can close or reopen accounts.');
    }

    public function close(int $userId): void
    {
        $this->authorizeAdmin();
        $user = User::whereNotNull('deletion_requested_at')->whereNull('closed_at')->findOrFail($userId);
        app(AccountDeletionService::class)->close($user, auth()->user(), 'partner request');
        $this->flashMessage = "Closed {$user->name}. Details are erased in ".AccountDeletionService::ERASE_AFTER_DAYS.' days.';
    }

    public function reopen(int $userId): void
    {
        $this->authorizeAdmin();
        $user = User::whereNotNull('closed_at')->whereNull('erased_at')->findOrFail($userId);
        app(AccountDeletionService::class)->reopen($user, auth()->user());
        $this->flashMessage = "Reopened {$user->name}.";
    }

    public function render()
    {
        return view('livewire.account-deletions.index', [
            'waiting' => User::whereNotNull('deletion_requested_at')->whereNull('closed_at')->orderBy('deletion_requested_at')->get(),
            'closed' => User::whereNotNull('closed_at')->whereNull('erased_at')->orderBy('closed_at')->get(),
            'days' => AccountDeletionService::ERASE_AFTER_DAYS,
        ])->layout('layouts.admin', ['title' => 'Account deletions']);
    }
}
