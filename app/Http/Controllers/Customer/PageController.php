<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\ContentPage;
use App\Models\Faq;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Public, unauthenticated customer pages: legal documents, the help centre,
 * how-it-works, and the honest "coming soon" placeholder (Phase B).
 *
 * Legal content is read from the SAME `content_pages` rows and by the SAME
 * rule the existing public API already uses
 * (App\Http\Controllers\API\ContentController::page(): active-only, 404 for
 * anything else, never leaking that a draft exists). The text itself comes
 * from storage/legal-content/*.md via LegalContentSeeder and is supplied by
 * the business — nothing here authors, edits, summarises or supplements it,
 * and no new legal claim is introduced anywhere in the customer app.
 */
class PageController extends Controller
{
    /**
     * Destinations whose real screens land in Phase D–E. Whitelisted (and
     * enforced with whereIn() on the route) so this can only ever render a
     * label chosen here, never arbitrary user-supplied text reflected back
     * into the page.
     *
     * Phase C removed `services`, `categories` and `offers` from this list:
     * all three now have real screens (routes/web.php), so a placeholder for
     * them would be a worse answer than the thing itself.
     */
    public const COMING_SOON_FEATURES = [
        'booking',
        'bookings',
        'languages',
        'partners',
    ];

    /**
     * Each entry states plainly what does not exist yet. No entry promises a
     * date — none is known, and inventing one would be a claim we cannot
     * keep.
     *
     * @var array<string, array{title: string, body: string}>
     */
    private const COMING_SOON_COPY = [
        'booking' => [
            'title' => 'Online booking is on its way',
            'body' => 'Booking a service through the web app is being built. Our team can still take your booking over the phone today.',
        ],
        'bookings' => [
            'title' => 'Your bookings are on their way',
            'body' => 'Viewing and tracking your bookings in the web app is being built.',
        ],
        'languages' => [
            'title' => 'More languages are on the way',
            'body' => 'The web app is currently available in English only.',
        ],
        'partners' => [
            'title' => 'Partner sign-up is on its way',
            'body' => 'Joining as a service professional through the web app is being built. Our team is already onboarding professionals directly in the meantime.',
        ],
    ];

    public function privacy(): View
    {
        return $this->contentPage('privacy-policy');
    }

    public function terms(): View
    {
        return $this->contentPage('terms-and-conditions');
    }

    /** Help centre — the real, active `faqs` rows, in the same order ContentController::faqs() returns them. */
    public function help(): View
    {
        $faqs = Faq::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'category', 'question', 'answer'])
            ->groupBy(fn (Faq $faq) => $faq->category ?: 'General');

        return view('customer.help', ['faqGroups' => $faqs]);
    }

    public function howItWorks(): View
    {
        return view('customer.how-it-works');
    }

    public function comingSoon(string $feature): View
    {
        $copy = self::COMING_SOON_COPY[$feature] ?? [
            'title' => 'This is on its way',
            'body' => 'This part of the web app is still being built.',
        ];

        return view('customer.coming-soon', [
            'heading' => $copy['title'],
            'body' => $copy['body'],
        ]);
    }

    /** 404s for a draft (is_active=false) page exactly like a missing one — same rule as ContentController::page(). */
    private function contentPage(string $slug): View
    {
        $page = ContentPage::where('slug', $slug)->where('is_active', true)->first();

        abort_if($page === null, 404);

        return view('customer.page', [
            'page' => $page,
            'body' => $this->renderBody($page->content),
        ]);
    }

    /**
     * Markdown -> HTML for a stored content page.
     *
     * `html_input => escape` means raw HTML inside the stored content is
     * displayed as text rather than executed, and `allow_unsafe_links`
     * blocks javascript: hrefs. Both matter because `content_pages` is
     * admin-editable through Cms\Manage while these pages are public and
     * unauthenticated — without escaping, one careless or compromised admin
     * edit becomes stored XSS against every visitor.
     *
     * Headings are then demoted one level. The page template already renders
     * the row's `title` as the document's <h1>, and the real seeded
     * documents repeat that title as a markdown `#` heading — which produced
     * two <h1> elements on the same page (caught by an accessibility probe
     * in browser testing, not by any unit test). Demoting keeps a single
     * top-level heading and nests the document's own structure correctly
     * beneath it. This changes heading LEVELS only: no word of the legal
     * text is altered, added, or removed.
     */
    private function renderBody(string $markdown): string
    {
        $html = (string) Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        // Walk h5 -> h1 so an already-demoted tag is never demoted twice.
        // h6 has nowhere lower to go and is left as-is.
        foreach ([5, 4, 3, 2, 1] as $level) {
            $html = str_replace(
                ["<h{$level}>", "</h{$level}>"],
                ['<h'.($level + 1).'>', '</h'.($level + 1).'>'],
                $html,
            );
        }

        return $html;
    }
}
