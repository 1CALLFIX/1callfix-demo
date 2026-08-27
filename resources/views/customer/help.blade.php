{{-- Help centre. Every question and answer is a real, active `faqs` row —
     the same source and ordering the public API's /api/faqs already serves.
     Nothing is written here. --}}
<x-layouts.customer title="Help centre">
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
        <header>
            <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">Help centre</h1>
            <p class="mt-3 text-base text-slate-600">
                Answers to the questions we get asked most.
            </p>
        </header>

        @forelse ($faqGroups as $category => $faqs)
            <section aria-labelledby="faq-group-{{ \Illuminate\Support\Str::slug($category) }}" class="mt-12">
                <h2 id="faq-group-{{ \Illuminate\Support\Str::slug($category) }}"
                    class="text-lg font-semibold text-slate-900">
                    {{ $category }}
                </h2>
                <div class="mt-4 divide-y divide-slate-200 border-y border-slate-200">
                    @foreach ($faqs as $faq)
                        {{-- Native <details>: keyboard-operable and announced
                             by screen readers with no JavaScript at all. --}}
                        <details class="group py-4">
                            <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-4 rounded text-left text-base font-medium text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                                <span>{{ $faq->question }}</span>
                                <span aria-hidden="true" class="shrink-0 text-slate-400 transition group-open:rotate-180">
                                    <x-icon name="chevron-down" class="h-4 w-4" />
                                </span>
                            </summary>
                            <p class="mt-3 text-sm leading-relaxed text-slate-600">{{ $faq->answer }}</p>
                        </details>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="mt-12">
                <x-ui.empty-state icon="chat"
                                  title="No help articles yet"
                                  description="Frequently asked questions will appear here once they are published." />
            </div>
        @endforelse

        <div class="mt-12 rounded-xl border border-slate-200 bg-slate-50 p-6">
            <h2 class="text-base font-semibold text-slate-900">Still need a hand?</h2>
            <p class="mt-1.5 text-sm text-slate-600">
                Read our
                <a href="{{ route('customer.terms') }}" class="font-medium text-slate-900 underline underline-offset-4">Terms of Use</a>
                and
                <a href="{{ route('customer.privacy') }}" class="font-medium text-slate-900 underline underline-offset-4">Privacy Policy</a>,
                or see
                <a href="{{ route('customer.how-it-works') }}" class="font-medium text-slate-900 underline underline-offset-4">how {{ \App\Models\Setting::get('branding.platform_name', '1CallFix') }} works</a>.
            </p>
        </div>
    </div>
</x-layouts.customer>
