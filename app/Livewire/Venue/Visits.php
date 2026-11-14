<?php

namespace App\Livewire\Venue;

use App\Livewire\Venue\Concerns\InteractsWithHalls;
use App\Models\HouseListing;
use App\Models\HouseVisit;
use App\Services\Homes\HouseListingService;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Rental portal → Visits (houses for rent): people who want to see the owner's homes. New requests first with a big
 * Accept (optional note, e.g. "come at 5 PM") and Say no (tap a reason or type one). Accepted visits show the
 * customer's name and number. Never another owner's request (404).
 */
class Visits extends Component
{
    use InteractsWithHalls;

    #[Url(as: 'show')]
    public string $tab = 'new';

    public ?int $answeringId = null;
    public string $note = '';
    public string $reason = '';
    public string $error = '';

    public const REASONS = ['Already rented', 'Not free on that day', 'Not suitable for this tenant'];

    private function own()
    {
        $ids = HouseListing::where('provider_id', $this->partner()->id)->pluck('id');

        return HouseVisit::with('listing')->whereIn('house_listing_id', $ids);
    }

    private function visit(int $id): HouseVisit
    {
        return (clone $this->own())->find($id) ?? abort(404);
    }

    public function answer(int $id): void
    {
        $this->visit($id);
        $this->answeringId = $id;
        $this->reset('note', 'reason', 'error');
    }

    public function accept(int $id, HouseListingService $homes): void
    {
        $visit = $this->visit($id); // another owner's request: 404 (outside the try: an HTTP 404 is a RuntimeException too)
        try {
            $homes->accept($visit, auth()->user(), $this->answeringId === $id ? $this->note : null);
            $this->reset('answeringId', 'note', 'error');
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function decline(HouseListingService $homes): void
    {
        if ($this->answeringId === null) {
            return;
        }
        $visit = $this->visit($this->answeringId);
        try {
            $homes->decline($visit, auth()->user(), $this->reason);
            $this->reset('answeringId', 'reason', 'error');
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $today = now()->toDateString();

        return view('livewire.venue.visits', [
            'counts' => [
                'new' => (clone $this->own())->where('status', 'requested')->count(),
                'accepted' => (clone $this->own())->where('status', 'accepted')->where('visit_date', '>=', $today)->count(),
            ],
            'visits' => match ($this->tab) {
                'accepted' => (clone $this->own())->where('status', 'accepted')->where('visit_date', '>=', $today)->orderBy('visit_date')->limit(100)->get(),
                'past' => (clone $this->own())->where(fn ($q) => $q->whereNotIn('status', HouseVisit::OPEN)->orWhere('visit_date', '<', $today))->latest('id')->limit(50)->get(),
                default => (clone $this->own())->where('status', 'requested')->orderBy('visit_date')->limit(100)->get(),
            },
        ])->layout('components.layouts.venue', ['title' => 'Visits']);
    }
}
