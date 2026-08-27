{{--
    Legal / CMS content page. The body is the exact stored `content_pages`
    row — supplied by the business via storage/legal-content/*.md and seeded
    by LegalContentSeeder. It is rendered, never rewritten, summarised or
    supplemented.

    $body arrives already converted and sanitised by
    App\Http\Controllers\Customer\PageController::renderBody() — see that
    method for the escaping and heading-demotion reasoning.
--}}
<x-layouts.customer :title="$page->title">
    <article class="mx-auto max-w-3xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
        <header>
            <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">{{ $page->title }}</h1>
            @if ($page->updated_at)
                <p class="mt-3 text-sm text-slate-500">
                    Last updated
                    <time datetime="{{ $page->updated_at->toDateString() }}">{{ $page->updated_at->format('j F Y') }}</time>
                </p>
            @endif
        </header>

        {{-- Tailwind's typography plugin is not installed in this project, so
             the document rhythm is set with explicit child selectors rather
             than a `prose` class that would silently do nothing. --}}
        <div class="mt-10 text-[0.9375rem] leading-relaxed text-slate-700
                    [&_h1]:mt-10 [&_h1]:mb-4 [&_h1]:text-2xl [&_h1]:font-bold [&_h1]:text-slate-900
                    [&_h2]:mt-10 [&_h2]:mb-3 [&_h2]:text-xl [&_h2]:font-bold [&_h2]:text-slate-900
                    [&_h3]:mt-8 [&_h3]:mb-2 [&_h3]:text-base [&_h3]:font-semibold [&_h3]:text-slate-900
                    [&_p]:mb-4
                    [&_ul]:mb-4 [&_ul]:list-disc [&_ul]:pl-6 [&_ol]:mb-4 [&_ol]:list-decimal [&_ol]:pl-6
                    [&_li]:mb-1.5
                    [&_a]:font-medium [&_a]:text-slate-900 [&_a]:underline [&_a]:underline-offset-2
                    [&_strong]:font-semibold [&_strong]:text-slate-900
                    [&_table]:my-4 [&_table]:w-full [&_table]:text-sm
                    [&_td]:border [&_td]:border-slate-200 [&_td]:px-2 [&_td]:py-1
                    [&_th]:border [&_th]:border-slate-200 [&_th]:px-2 [&_th]:py-1 [&_th]:text-left">
            {!! $body !!}
        </div>
    </article>
</x-layouts.customer>
