<?php

namespace App\Livewire\Chat;

use App\Models\Booking;
use App\Services\ActivityLogger;
use App\Services\AuthorizationService;
use App\Services\ChatService;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Phase 21 item TECH-4 — Option A only, read-only admin chat viewer.
 * Closes risk register item 15: Universal Chat (mission Phase 6) has been
 * fully functional between real Customer/Partner/Worker participants with
 * ZERO admin-facing surface — support staff had no way to investigate a
 * dispute without a raw DB query. This screen adds exactly that: browse
 * bookings with chat activity, open one, read every message on it.
 *
 * Deliberately does NOT add send/edit/hide/delete/block/flag/suspend —
 * that was evaluated as "Option B" in this item's own design audit and
 * explicitly NOT authorized. No chat.moderate permission exists. No
 * chat_messages schema change was made.
 */
class Manage extends Component
{
    use WithPagination;

    public string $search = '';
    public ?int $selectedBookingId = null;

    /**
     * chat.view didn't exist anywhere before this phase (unlike almost
     * every other screen this backlog touched, which reused an
     * already-seeded slug) — see the permission migration's own docblock
     * for why it starts super-admin-only.
     */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('chat.view'), 403, 'You do not have permission to view chat conversations.');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    private function scopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    /**
     * The ONE query every booking this screen can ever touch is derived
     * from — reused for the list, for re-resolving a selected booking, AND
     * as the authorization boundary selectBooking() checks against. Same
     * AuthorizationService::scopeQuery() convention Bookings\Index/Show,
     * Commissions\Index, Payments\Index etc. already use, applied directly
     * to Booking (chat has no franchise/zone of its own — it only ever
     * reaches one through the booking it belongs to).
     */
    private function scopedBookingsQuery()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(Booking::query(), auth()->user(), 'chat.view', $this->scopeColumns())
            ->with(['customer', 'provider.user', 'assignedWorker.user', 'franchise']);
    }

    /**
     * The real authorization boundary for opening a conversation — a
     * booking id that isn't in the caller's own scoped query (whether
     * reached by clicking a legitimately-listed row, or by a
     * manipulated/guessed id via the Livewire wire protocol) is rejected
     * here, before anything is stored in $selectedBookingId, before any
     * message is ever fetched. Also the one place a genuine "open"
     * action gets logged — once per real click, not once per re-render
     * (see getSelectedBookingProperty()'s own docblock for why the data
     * fetch path below does NOT also log).
     */
    public function selectBooking(int $bookingId): void
    {
        $booking = $this->scopedBookingsQuery()->find($bookingId);
        abort_if(! $booking, 404, 'Conversation not found, or you do not have access to it.');

        $this->selectedBookingId = $bookingId;

        ActivityLogger::logModel(auth()->user(), $booking, "Viewed chat conversation for booking {$booking->code}");
    }

    public function backToList(): void
    {
        $this->selectedBookingId = null;
    }

    /**
     * Re-derived through the SAME scoped query on every access — defense
     * in depth against a tampered wire:snapshot setting selectedBookingId
     * directly without ever going through selectBooking()'s own check
     * above. An out-of-scope id here simply resolves to null (render()
     * then falls back to the list view), never real data — no abort,
     * no distinguishing signal, same fail-safe shape as every other
     * scoped query in this codebase. Deliberately does NOT log here —
     * this can be evaluated multiple times per request (Livewire caches
     * computed properties per-request, but a real booking stays selected
     * across MANY separate requests while open) and would otherwise spam
     * the audit trail with one entry per re-render instead of one per
     * genuine open action.
     */
    public function getSelectedBookingProperty(): ?Booking
    {
        if (! $this->selectedBookingId) {
            return null;
        }

        return $this->scopedBookingsQuery()->find($this->selectedBookingId);
    }

    public function getMessagesProperty(): Collection
    {
        return $this->selectedBooking
            ? app(ChatService::class)->adminConversation($this->selectedBooking)
            : collect();
    }

    /** 'Customer' / 'Partner' / 'Worker' / 'Unknown' — reuses the same three real pairings ChatService::participantIds() already derives, purely for display labeling here. */
    public function participantRole(Booking $booking, ?int $userId): string
    {
        if (! $userId) {
            return 'Unknown';
        }
        if ($userId === $booking->customer_id) {
            return 'Customer';
        }
        if ($userId === $booking->provider?->user_id) {
            return 'Partner';
        }
        if ($userId === $booking->assignedWorker?->user_id) {
            return 'Worker';
        }

        return 'Unknown';
    }

    public function render()
    {
        if ($this->selectedBooking) {
            return view('livewire.chat.manage', [
                'booking' => $this->selectedBooking,
                'messages' => $this->messages,
            ])->layout('layouts.admin', ['title' => 'Chat']);
        }

        $bookings = $this->scopedBookingsQuery()
            ->whereHas('chatMessages')
            ->withCount('chatMessages')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('code', 'like', "%{$this->search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$this->search}%"))
                ->orWhereHas('provider.user', fn ($p) => $p->where('name', 'like', "%{$this->search}%"))))
            ->latest('id')
            ->paginate(20);

        return view('livewire.chat.manage', [
            'booking' => null,
            'bookings' => $bookings,
        ])->layout('layouts.admin', ['title' => 'Chat']);
    }
}
