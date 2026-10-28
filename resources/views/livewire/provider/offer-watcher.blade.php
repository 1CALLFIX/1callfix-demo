{{-- No markup of its own: the banner/ring is the layout's `providerOfferAlert`.
     The poll is present only while online (see OfferWatcher). keep-alive: a
     hidden tab (screen off, another app in front) keeps checking instead of
     Livewire's default of skipping most ticks, so an offer is not missed. --}}
<div @if ($online) wire:poll.6s.keep-alive @endif class="hidden" aria-hidden="true"></div>
