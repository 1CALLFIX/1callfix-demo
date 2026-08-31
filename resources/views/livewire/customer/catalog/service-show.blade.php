{{--
    Service detail (Phase C). See the ServiceShow component's docblock for the
    two things that matter most about this screen:

      1. Every price is computed on the SERVER from database values. Nothing
         is added up in JavaScript and no client-supplied price is ever read.
      2. Option selection is displayed and priced, but is NOT yet carried into
         a booking — nothing in this application writes `booking_options`
         today. The total is therefore labelled as an estimate, not a quote,
         and the primary CTA goes to the honest Phase D placeholder rather
         than a checkout that does not exist.
--}}

<div class="mb-bottom-nav">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <x-customer.breadcrumbs :items="array_values(array_filter([
            ['label' => 'Home', 'url' => route('customer.home')],
            ['label' => 'Categories', 'url' => route('customer.categories.index')],
            $service->category ? ['label' => $service->category->name, 'url' => route('customer.categories.show', $service->category)] : null,
            ['label' => $service->name, 'url' => null],
        ]))" />

        <div class="mt-6 grid gap-10 lg:grid-cols-[minmax(0,1fr)_22rem]">

            {{-- ========================= Main column ========================= --}}
            <div class="min-w-0">

                {{-- Media --}}
                <div class="overflow-hidden rounded-2xl bg-slate-100">
                    @if ($card['image_url'])
                        {{-- The LCP element on this screen, so it loads eagerly
                             and at high priority while everything below stays
                             lazy. Explicit dimensions reserve the box. --}}
                        <img src="{{ $card['image_url'] }}" alt=""
                             fetchpriority="high" decoding="async"
                             width="1200" height="675"
                             class="aspect-video w-full object-cover">
                    @else
                        <div aria-hidden="true"
                             class="flex aspect-video w-full items-center justify-center text-5xl font-bold text-slate-400">
                            <x-customer.initial :name="$service->name" />
                        </div>
                    @endif
                </div>

                {{-- Title block --}}
                <div class="mt-6">
                    @if (! empty($card['badges']))
                        <div class="mb-3 flex flex-wrap gap-1.5">
                            @foreach ($card['badges'] as $badge)
                                <x-customer.badge-pill :badge="$badge" />
                            @endforeach
                        </div>
                    @endif

                    @if ($service->category)
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">
                            {{ $service->category->name }}@if ($service->subcategory) · {{ $service->subcategory->name }}@endif
                        </p>
                    @endif

                    <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $service->name }}</h1>

                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-slate-600">
                        <x-customer.rating :rating="$card['rating']" :count="$card['review_count']" />

                        @if ($card['duration_mins'])
                            <span class="inline-flex items-center gap-1.5">
                                <x-icon name="clock" class="h-4 w-4 text-slate-400" />
                                About {{ $card['duration_mins'] }} minutes
                            </span>
                        @endif

                        @if ($bookingCount > 0)
                            {{-- A real count of non-cancelled bookings for this
                                 service in the viewer's franchise. Shown only
                                 when it is greater than zero: "0 bookings" is
                                 accurate but reads as a warning, and a brand
                                 new service is not a worse service. --}}
                            <span class="inline-flex items-center gap-1.5">
                                <x-icon name="check-circle" class="h-4 w-4 text-slate-400" />
                                {{ number_format($bookingCount) }} {{ \Illuminate\Support\Str::plural('booking', $bookingCount) }} so far
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Description --}}
                @if ($service->description)
                    <section aria-labelledby="about-heading" class="mt-8">
                        <h2 id="about-heading" class="text-lg font-semibold text-slate-900">About this service</h2>
                        <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-slate-600">{{ $service->description }}</p>
                    </section>
                @endif

                {{-- =========================== Options ===========================
                     Real ServiceOptionGroup / ServiceOption rows with their real
                     price deltas. Single-choice groups render as a radio group,
                     multi-choice as checkboxes — the semantics the group's own
                     `allow_multiple` column already declares, so assistive
                     technology describes the choice correctly ("one of four" vs
                     "checkbox, not checked").
                --}}
                @if ($groups->isNotEmpty())
                    <section aria-labelledby="options-heading" class="mt-10">
                        <h2 id="options-heading" class="text-lg font-semibold text-slate-900">Configure your service</h2>
                        <p class="mt-1 text-sm text-slate-600">Choices here change the estimate. Nothing is booked yet.</p>

                        <div class="mt-4 space-y-6">
                            @foreach ($groups as $group)
                                @php
                                    $selectedForGroup = collect((array) ($selected[$group->id] ?? []))->map(fn ($id) => (int) $id);
                                @endphp

                                <fieldset class="rounded-xl border border-slate-200 p-4">
                                    <legend class="flex items-center gap-2 px-1 text-sm font-semibold text-slate-900">
                                        {{ $group->name }}
                                        @if ($group->is_required)
                                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-600">Required</span>
                                        @endif
                                        @if ($group->allow_multiple)
                                            <span class="text-xs font-normal text-slate-500">(choose any)</span>
                                        @endif
                                    </legend>

                                    <ul class="mt-3 space-y-2">
                                        @foreach ($group->options as $option)
                                            @php $isSelected = $selectedForGroup->contains($option->id); @endphp
                                            <li>
                                                <label class="flex min-h-11 cursor-pointer items-center justify-between gap-4 rounded-lg border p-3 transition
                                                              {{ $isSelected ? 'border-blue-600 bg-blue-50/60 ring-1 ring-blue-600' : 'border-slate-200 hover:border-blue-300' }}">
                                                    <span class="flex items-center gap-3">
                                                        <input type="{{ $group->allow_multiple ? 'checkbox' : 'radio' }}"
                                                               name="option-group-{{ $group->id }}"
                                                               value="{{ $option->id }}"
                                                               @checked($isSelected)
                                                               wire:click="{{ $group->allow_multiple ? 'toggleOption' : 'selectOption' }}({{ $group->id }}, {{ $option->id }})"
                                                               class="h-4 w-4 shrink-0 border-slate-300 text-slate-900 focus:ring-2 focus:ring-blue-600 focus:ring-offset-2">
                                                        <span class="text-sm text-slate-900">{{ $option->name }}</span>
                                                    </span>

                                                    <span class="shrink-0 text-sm font-medium text-slate-700">
                                                        @if ((float) $option->price_delta > 0)
                                                            +{{ $currencySymbol }}{{ number_format((float) $option->price_delta, 2) }}
                                                        @elseif ((float) $option->price_delta < 0)
                                                            −{{ $currencySymbol }}{{ number_format(abs((float) $option->price_delta), 2) }}
                                                        @else
                                                            <span class="text-slate-400">Included</span>
                                                        @endif
                                                    </span>
                                                </label>
                                            </li>
                                        @endforeach
                                    </ul>
                                </fieldset>
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- =========================== Reviews ===========================
                     Real `reviews` rows reached through this service's bookings.
                     Only reviews carrying an actual written comment are listed —
                     a bare star already counts towards the average above and adds
                     nothing as a list entry. No section at all when there are none.
                --}}
                @if ($reviews->isNotEmpty())
                    <section aria-labelledby="reviews-heading" class="mt-10">
                        <h2 id="reviews-heading" class="text-lg font-semibold text-slate-900">What customers said</h2>
                        <ul class="mt-4 space-y-4">
                            @foreach ($reviews as $review)
                                <li class="rounded-xl border border-slate-200 p-4">
                                    <div class="flex items-center justify-between gap-3">
                                        <x-customer.rating :rating="(float) $review->rating" :count="null" :show-count="false" />
                                        <span class="text-xs text-slate-500">{{ $review->created_at?->diffForHumans() }}</span>
                                    </div>
                                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $review->comment }}</p>
                                    @if ($review->customer)
                                        <p class="mt-2 text-xs text-slate-500">— {{ \Illuminate\Support\Str::of($review->customer->name)->before(' ') }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                {{-- =========================== Related =========================== --}}
                @if ($related->isNotEmpty())
                    <section aria-labelledby="related-heading" class="mt-12">
                        <x-customer.section-heading id="related-heading" title="You might also need" />
                        <x-customer.service-rail :cards="$related" :currency-symbol="$currencySymbol" labelled-by="related-heading" class="mt-4" compact />
                    </section>
                @endif
            </div>

            {{-- ======================= Booking summary =======================
                 Sticky on desktop so the price stays visible while the customer
                 reads. On mobile it sits inline above the fold-out content AND
                 is repeated as a fixed bar at the bottom of the screen, which is
                 where a thumb already is.
            --}}
            <aside aria-labelledby="summary-heading" class="lg:sticky lg:top-24 lg:self-start">
                <div class="rounded-2xl border border-slate-200 p-5 shadow-sm">
                    <h2 id="summary-heading" class="sr-only">Price summary</h2>

                    <x-customer.price :card="$card" :currency-symbol="$currencySymbol" size="lg" />

                    @if ($card['flash_sale'])
                        <p class="mt-2 inline-flex items-center gap-1.5 rounded bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">
                            <x-icon name="bolt" class="h-3.5 w-3.5" />
                            Sale price
                            @if ($card['flash_sale']['remaining_quantity'] !== null)
                                · {{ $card['flash_sale']['remaining_quantity'] }} left
                            @endif
                        </p>
                    @endif

                    @if ($optionsTotal != 0.0)
                        <dl class="mt-4 space-y-1.5 border-t border-slate-200 pt-4 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-slate-600">Base</dt>
                                <dd class="text-slate-900">{{ $currencySymbol }}{{ number_format($card['price'], 2) }}</dd>
                            </div>
                            @foreach ($selectedOptions as $option)
                                <div class="flex justify-between gap-4">
                                    <dt class="min-w-0 truncate text-slate-600">{{ $option->name }}</dt>
                                    <dd class="shrink-0 text-slate-900">
                                        {{ (float) $option->price_delta < 0 ? '−' : '+' }}{{ $currencySymbol }}{{ number_format(abs((float) $option->price_delta), 2) }}
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    <div class="mt-4 flex items-baseline justify-between gap-4 border-t border-slate-200 pt-4">
                        <span class="text-sm font-medium text-slate-700">Estimated total</span>
                        <span class="text-xl font-bold text-slate-900">
                            {{ $currencySymbol }}{{ number_format($estimatedTotal, 2) }}
                        </span>
                    </div>

                    @if ($missingRequiredGroups->isNotEmpty())
                        <p role="status" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-900">
                            Choose {{ $missingRequiredGroups->pluck('name')->implode(' and ') }} to complete this estimate.
                        </p>
                    @endif

                    {{-- Honest about what the number is. The final charge is
                         computed server-side at booking time by
                         CreateBookingAction; nothing shown here is a
                         commitment. --}}
                    <p class="mt-3 text-xs leading-relaxed text-slate-500">
                        @if ($service->price_type === 'quote_on_inspection')
                            Estimate only. Your final price is confirmed when you book, and may change after the professional inspects the job.
                        @else
                            Estimate only. Your final price is confirmed when you book.
                        @endif
                    </p>

                    <a href="{{ route('customer.book', $service) }}"
                       class="mt-4 flex min-h-12 w-full items-center justify-center rounded-lg bg-blue-600 px-6 text-sm font-semibold text-white transition hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                        Book now
                    </a>

                    {{-- ======================= Add to cart =======================
                         Collect several services and book them together as one
                         bundle at checkout. Optional preferred time; the option
                         selection above and the estimate are carried over but
                         re-priced authoritatively at checkout.
                    --}}
                    <div class="mt-3 rounded-lg border border-slate-200 p-3">
                        <label for="cart-preferred-at" class="block text-xs font-medium text-slate-600">Preferred time (optional)</label>
                        <input type="datetime-local" id="cart-preferred-at" wire:model="preferredAt"
                               class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-blue-500 focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-blue-600">

                        <label for="cart-note" class="mt-3 block text-xs font-medium text-slate-600">Note for the professional (optional)</label>
                        <textarea id="cart-note" wire:model="customerNote" rows="2" maxlength="1000"
                                  class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-blue-500 focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-blue-600"></textarea>

                        <button type="button" wire:click="addToCart"
                                class="mt-3 flex min-h-12 w-full items-center justify-center gap-2 rounded-lg border border-blue-600 px-6 text-sm font-semibold text-blue-700 transition hover:bg-blue-600 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                            <x-icon name="shopping-bag" class="h-4 w-4" />
                            Add to cart
                        </button>

                        @error('cart')
                            <p role="alert" class="mt-2 text-xs text-red-600">{{ $message }}</p>
                        @enderror

                        @if ($cartNotice !== '')
                            <p role="status" class="mt-2 text-xs text-emerald-700">
                                {{ $cartNotice }}
                                <a href="{{ route('customer.cart') }}" wire:navigate class="font-semibold underline underline-offset-2">View cart</a>
                            </p>
                        @endif
                    </div>

                    {{-- ====================== Availability ======================
                         A real count from DispatchService::nearbyForService() —
                         the same read-only call GET /api/providers/nearby uses.
                         Rendered only when the question can be answered: no zone
                         chosen, or a zone with no centre coordinate, shows the
                         prompt instead. "0 available" and "we don't know" are
                         different statements and must not look alike.
                    --}}
                    <div class="mt-4 border-t border-slate-200 pt-4 text-sm">
                        @if ($availableProviderCount !== null && $activeZone)
                            @if ($availableProviderCount > 0)
                                <p class="inline-flex items-start gap-2 text-slate-600">
                                    <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                                    <span>{{ $availableProviderCount }} {{ \Illuminate\Support\Str::plural('professional', $availableProviderCount) }} available around {{ $activeZone->name }} right now.</span>
                                </p>
                            @else
                                <p class="inline-flex items-start gap-2 text-slate-600">
                                    <x-icon name="exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                                    <span>No professionals are online around {{ $activeZone->name }} at the moment. You can still book — we will match you when someone comes on shift.</span>
                                </p>
                            @endif
                        @else
                            <p class="inline-flex items-start gap-2 text-slate-600">
                                <x-icon name="map" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                                <span>Set your area to see availability and local pricing.</span>
                            </p>
                        @endif
                    </div>
                </div>
            </aside>
        </div>
    </div>

    {{-- Mobile sticky CTA. `bottom-16` clears the fixed bottom navigation
         (4rem tall — see components/customer/bottom-nav.blade.php), and the
         safe-area padding keeps it above the iOS home indicator. Hidden from
         `lg` up, where the sticky sidebar already does this job. --}}
    <div class="pb-safe fixed inset-x-0 bottom-16 z-30 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur lg:hidden">
        <div class="mx-auto flex max-w-3xl items-center justify-between gap-4">
            <span>
                <span class="block text-[11px] uppercase tracking-wide text-slate-500">Estimated total</span>
                <span class="block text-lg font-bold text-slate-900">{{ $currencySymbol }}{{ number_format($estimatedTotal, 2) }}</span>
            </span>
            <a href="{{ route('customer.book', $service) }}"
               class="inline-flex min-h-11 items-center rounded-lg bg-blue-600 px-6 text-sm font-semibold text-white transition hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                Book now
            </a>
        </div>
    </div>
</div>
