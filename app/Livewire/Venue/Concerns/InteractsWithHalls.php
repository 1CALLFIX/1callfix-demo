<?php

namespace App\Livewire\Venue\Concerns;

use App\Models\Hall;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Collection;

/** The signed-in owner's own halls — never another owner's (404). */
trait InteractsWithHalls
{
    protected function partner(): Provider
    {
        return Provider::with('user')->where('user_id', auth()->id())->firstOrFail();
    }

    /** @return Collection<int, Hall> */
    protected function halls(): Collection
    {
        return Hall::with(['franchise', 'slots'])->where('provider_id', $this->partner()->id)->orderBy('id')->get();
    }

    protected function ownHall(int $hallId): Hall
    {
        return Hall::with(['franchise', 'slots'])->where('provider_id', $this->partner()->id)->find($hallId) ?? abort(404);
    }
}
