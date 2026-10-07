<?php

namespace App\Support\PartnerPage;

use App\Models\ContentPage;
use App\Support\PartnerPage\PartnerPageSettings as P;

/**
 * REF 1CF-PARTNER-PAGE-001 — the shared customer footer's link groups. The groups that were hardcoded in the footer
 * view are the code default here; an admin-saved list (Admin → Partner page) replaces them only when it passes the
 * same schema check the admin screen enforces. An empty, invalid or schema-failing setting, or any failure reading the
 * settings store or cache, renders the default: a bad value can never blank or break the footer on every page.
 */
final class FooterLinks
{
    /** @return array<string, list<array{label:string, href:string}>> heading => links */
    public static function defaults(): array
    {
        return [
            'Company' => [
                ['label' => 'How It Works', 'href' => route('customer.how-it-works')],
                ['label' => 'Help & FAQs', 'href' => route('customer.help')],
            ],
            'Services' => [
                ['label' => 'Browse services', 'href' => route('customer.services.index')],
                ['label' => 'Categories', 'href' => route('customer.categories.index')],
                ['label' => 'Offers', 'href' => route('customer.offers')],
            ],
            'For professionals' => [
                ['label' => 'Join as a Partner', 'href' => route('customer.partners')],
            ],
            'Legal' => [
                ['label' => 'Privacy Policy', 'href' => route('customer.privacy')],
                ['label' => 'Terms of Use', 'href' => route('customer.terms')],
            ],
        ];
    }

    /** The admin-saved groups, or null when there is nothing usable (then the default applies). */
    public static function custom(): ?array
    {
        try {
            $raw = P::text('footer.groups');
            if (! is_string($raw) || trim($raw) === '') {
                return null;
            }
            $data = json_decode($raw, true);
            if (! is_array($data) || P::validate(['footer.groups' => $raw]) !== []) {
                return null;
            }

            $groups = [];
            foreach ($data as $group) {
                $groups[$group['title']] = array_map(fn ($l) => ['label' => $l['label'], 'href' => $l['href']], $group['links']);
            }

            return $groups ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Groups to render. CMS pages the admin ticked "show in footer" are appended to the "Company" group (or a new
     * "Company" group) exactly as before, whichever source the groups came from.
     *
     * @return array<string, list<array{label:string, href:string}>>
     */
    public static function groups(): array
    {
        $columns = self::custom() ?? self::defaults();

        $footerPages = ContentPage::query()
            ->where('is_active', true)->where('show_in_footer', true)
            ->whereNotIn('slug', ['privacy', 'terms', 'privacy-policy', 'terms-and-conditions'])
            ->orderBy('footer_order')->orderBy('title')->get(['slug', 'title']);

        foreach ($footerPages as $page) {
            $columns['Company'][] = ['label' => $page->title, 'href' => url('/'.$page->slug)];
        }

        return $columns;
    }

    /** The optional contact line under the brand blurb; hidden when empty or when the store fails. */
    public static function contactLine(): ?string
    {
        try {
            return P::text('footer_contact');
        } catch (\Throwable) {
            return null;
        }
    }
}
