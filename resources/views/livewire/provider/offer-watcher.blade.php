{{-- No markup of its own: the banner/ring is the layout's `providerOfferAlert`.
     The poll is present only while online (see OfferWatcher). --}}
<div @if ($online) wire:poll.6s @endif class="hidden" aria-hidden="true"></div>
