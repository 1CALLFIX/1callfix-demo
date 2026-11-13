<?php

namespace App\Livewire\Halls;

use App\Models\HallDamageClaim;
use App\Services\Halls\HallDamageClaimService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin → Rental → Damage claims (Function halls R4): the queue of hall deposits held after the hall reported damage,
 * oldest first, with age, amounts, franchise, status and escalation. One card per claim: how much the hall gets (the
 * rest goes back to the customer), a reason, one button. Money approval model in HallDamageClaimService.
 */
class DamageClaims extends Component
{
    use WithPagination;

    #[Url(as: 'show')]
    public string $filter = 'waiting';

    /** @var array<int, string> claim id => the hall's amount typed */
    public array $amounts = [];

    /** @var array<int, string> claim id => reason typed */
    public array $reasons = [];

    public function mount(HallDamageClaimService $claims): void
    {
        abort_unless($claims->canEnter(auth()->user()), 403, 'You do not have permission to decide hall damage claims.');
    }

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    private function claim(HallDamageClaimService $claims, int $id): HallDamageClaim
    {
        return $claims->visibleTo(HallDamageClaim::query(), auth()->user())->find($id) ?? abort(404);
    }

    private function done(array $result, int $id): void
    {
        session()->flash($result['ok'] ? 'message' : 'error', $result['message']);
        if ($result['ok']) {
            unset($this->reasons[$id]);
        }
    }

    public function decide(int $id, HallDamageClaimService $claims): void
    {
        $c = $this->claim($claims, $id);
        $amount = $this->amounts[$id] ?? (string) (float) $c->asked_amount;
        if (! is_numeric($amount)) {
            session()->flash('error', 'Enter the amount the hall gets (0 or more).');

            return;
        }
        $this->done($claims->decide($c, auth()->user(), (float) $amount, (string) ($this->reasons[$id] ?? '')), $id);
    }

    public function approve(int $id, HallDamageClaimService $claims): void
    {
        $this->done($claims->approve($this->claim($claims, $id), auth()->user(), (string) ($this->reasons[$id] ?? '')), $id);
    }

    public function reject(int $id, HallDamageClaimService $claims): void
    {
        $this->done($claims->reject($this->claim($claims, $id), auth()->user(), (string) ($this->reasons[$id] ?? '')), $id);
    }

    public function retry(int $id, HallDamageClaimService $claims): void
    {
        $this->done($claims->retry($this->claim($claims, $id), auth()->user()), $id);
    }

    public function render(HallDamageClaimService $claims)
    {
        $query = $claims->visibleTo(HallDamageClaim::query(), auth()->user())->with(['booking.hall', 'booking.customer', 'franchise', 'requestedBy', 'approvedBy']);
        $rows = (clone $query)->when($this->filter === 'waiting', fn ($q) => $q->whereIn('status', HallDamageClaim::WAITING), fn ($q) => $q->where('status', HallDamageClaim::SETTLED))
            ->orderBy('created_at')->paginate(20);

        return view('livewire.halls.damage-claims', [
            'claims' => $rows,
            'waiting' => (clone $query)->whereIn('status', HallDamageClaim::WAITING)->count(),
            'service' => $claims,
            'tz' => app(\App\Services\TimezoneResolver::class)->platformTimezone(),
        ])->layout('layouts.admin', ['title' => 'Hall damage claims']);
    }
}
